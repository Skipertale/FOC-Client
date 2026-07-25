import asyncio
import concurrent.futures
import os
import time
import traceback
from dataclasses import dataclass, field
from typing import Dict, List, Optional, Sequence
from urllib import parse

import discord
from discord import app_commands
from discord.ext import commands as discord_commands
from discord.utils import escape_markdown
from discord.errors import Forbidden, HTTPException

from server import commands as ao_commands
from server import database
from server import whitelist as wl_mod
from server.client_manager import ClientManager
from server.commands.hubs import ooc_cmd_gm_execute
from server.exceptions import ArgumentError, ClientError, ServerError
from server.mysql_helper import mysql_db


DISCORD_LOGIN_PROFILE = "Discord Approval"


# ─── helpers shared by login_approval ───

class NullTransport:
    def write(self, data):
        return None

    def close(self):
        return None


class DiscordCommandProxy(ClientManager.Client):
    def __init__(self, server, member: Optional[discord.abc.User] = None):
        super().__init__(server, NullTransport(), -9999, None)
        self.server = server
        self.transport = NullTransport()
        self.id = -9999
        self.ipid = None
        self.char_id = -1
        self.is_mod = True
        self.mod_profile_name = DISCORD_LOGIN_PROFILE
        self.hdid = f"discord:{getattr(member, 'id', 'system')}"
        base_name = getattr(member, "display_name", None) or getattr(member, "name", None) or "DiscordAdmin"
        self.name = str(base_name)
        self._showname = str(base_name)
        self.area = server.hub_manager.default_hub().default_area()
        self.area = self.server.hub_manager.default_hub().default_area()
        self.messages: List[str] = []

    @property
    def showname(self):
        return self._showname

    @showname.setter
    def showname(self, value):
        self._showname = value

    def send_raw_message(self, msg):
        return None

    def send_command(self, command, *args):
        return None

    def send_ooc(self, msg):
        self.messages.append(str(msg))

    def disconnect(self):
        return None

    def get_output(self) -> str:
        return "\n".join(m for m in self.messages if m is not None).strip()


@dataclass
class PendingLoginRequest:
    request_id: str
    client: object
    approver_id: Optional[int]
    channel_id: int
    message_id: Optional[int] = None
    created_at: float = field(default_factory=time.time)
    timeout_task: Optional[asyncio.Task] = None
    resolved: bool = False


@dataclass
class LogStreamSession:
    message: discord.Message
    owner_id: int
    stop_event: asyncio.Event = field(default_factory=asyncio.Event)
    last_payload: str = ""
    task: Optional[asyncio.Task] = None


# ─── UnifiedBot — replaces Bridgebot + LoginApproverBot + WhitelistBot ───

class UnifiedBot(discord_commands.Bot):
    def __init__(self, server):
        intents = discord.Intents.all()
        super().__init__(command_prefix="$", intents=intents)
        self.server = server

        # --- bridgebot state ---
        bridge_cfg = self.server.config.get("bridgebot", {})
        self.bridge_enabled = bool(bridge_cfg.get("enabled"))
        self.pending_messages: List[list] = []
        self.hub_id = bridge_cfg.get("hub_id")
        self.area_id = bridge_cfg.get("area_id")
        self.target_channel = bridge_cfg.get("channel")
        self._bridge_channel = None

        # --- login_approver state ---
        login_cfg = self.server.config.get("login_approval", {})
        self.login_enabled = bool(login_cfg.get("enabled"))
        self.login_pending_requests: Dict[str, PendingLoginRequest] = {}
        self.pending_by_client: Dict[int, str] = {}
        self.log_streams: Dict[int, LogStreamSession] = {}
        self._adminka_registered = False

        # --- whitelist state ---
        wl_cfg = self.server.config.get("whitelist", {})
        self.whitelist_enabled = bool(wl_cfg.get("enabled"))
        self.whitelist_pending_requests: Dict[str, dict] = {}
        self._whitelist_registered = False

        # --- gm request state ---
        self.gm_pending_requests: Dict[str, dict] = {}

        self.add_listener(self._on_interaction_diag, 'interaction')

    # ── startup ──

    async def init(self, token: str):
        if not token:
            print("[UnifiedBot] Нет токена, бот не запущен.")
            return
        print("[UnifiedBot] Запуск единого Discord-бота...")
        try:
            await self.start(token)
        except Exception as exc:
            print(f"[UnifiedBot] Ошибка запуска: {exc}")
            traceback.print_exc()

    async def _on_interaction_diag(self, interaction):
        if interaction.type == discord.InteractionType.component:
            d = interaction.data or {}
            mid = interaction.message.id if interaction.message else None
            print(f"[UnifiedBot] DIAG: component interaction -> custom_id={d.get('custom_id')} msg_id={mid}")

    def _view_store_size(self):
        try:
            return sum(len(v) for v in self._connection._view_store._views.values())
        except Exception:
            return -1

    async def setup_hook(self):
        # ── регистрируем команды (только локально, синк не нужен каждый старт) ──
        if self.whitelist_enabled:
            await self._setup_whitelist_commands()
        if self.login_enabled:
            await self._setup_login_commands()

    # ── on_ready ──

    async def _mysql_poll_task(self):
        await asyncio.sleep(5)
        loop = asyncio.get_running_loop()
        while True:
            try:
                resolved = await loop.run_in_executor(None, mysql_db.get_resolved_on_site)
                for row in resolved:
                    await self._process_site_resolution(row)
                    await loop.run_in_executor(None, mysql_db.mark_synced, row["id"])
                await asyncio.sleep(3)
            except ConnectionError:
                await asyncio.sleep(60)
            except Exception as exc:
                print(f"[UnifiedBot] MySQL poll error: {exc}")
                await asyncio.sleep(3)

    async def _process_site_resolution(self, row: dict):
        req_id = row["id"]
        req_type = row["type"]
        req_data = row["data"]
        status = row["status"]
        resolved_by = row["resolved_by"] or "Сайт"

        if req_type == "wl_join":
            request = self.whitelist_pending_requests.pop(req_id, None)
            if status == "approved":
                hdid = req_data.get("hdid", "")
                ipid = req_data.get("ipid", "")
                ip = req_data.get("ip", "")
                if hdid:
                    wl_mod.add_entry(hdid, "", "", resolved_by, "hdid")
                if ipid:
                    wl_mod.add_entry(ipid, "", "", resolved_by, "ipid")
                if ip:
                    wl_mod.add_entry(ip, "", "", resolved_by, "ip")
            await self._wl_update_discord_message(req_data, status == "approved", resolved_by)

        elif req_type == "gm_request":
            request = self.gm_pending_requests.pop(req_id, None)
            if request:
                client = request.get("client")
                if status == "approved":
                    if client in self.server.client_manager.clients:
                        ooc_cmd_gm_execute(client, request.get("arg", ""))
                        client.send_ooc(f"Ваш запрос {request.get('cmd', '')} одобрен администратором {resolved_by}.")
                else:
                    if client and hasattr(client, "send_ooc"):
                        client.send_ooc(f"Ваш запрос {request.get('cmd', '')} отклонён администратором {resolved_by}.")
            else:
                req_data = row.get("data", {})
                client_id = req_data.get("client_id")
                client = next((c for c in self.server.client_manager.clients if c.id == client_id), None)
                if client and status == "approved":
                    ooc_cmd_gm_execute(client, req_data.get("arg", ""))
                    client.send_ooc(f"Ваш запрос {req_data.get('cmd', '')} одобрен администратором {resolved_by}.")
            await self._wl_update_discord_message(req_data, status == "approved", resolved_by)

        elif req_type == "login_approval":
            request = self.login_pending_requests.pop(req_id, None)
            if request:
                from server.commands.admin import ooc_cmd_login
                client = request.client
                if client in self.server.client_manager.clients:
                    ooc_cmd_login(client, request.approver_id)
                self.pending_by_client.pop(id(client), None)
                await self._wl_update_discord_message(req_data, status == "approved", resolved_by)

    async def _wl_update_discord_message(self, req_data: dict, approved: bool, resolver: str):
        channel_id = req_data.get("channel_id")
        message_id = req_data.get("message_id")
        if not channel_id or not message_id:
            return
        channel = self.get_channel(channel_id)
        if channel is None:
            return
        try:
            message = await channel.fetch_message(message_id)
            embed = message.embeds[0] if message.embeds else None
            if embed:
                embed.color = discord.Color.green() if approved else discord.Color.red()
                label = "Одобрен" if approved else "Отклонён"
                embed.add_field(name="Статус", value=f"{label} администратором {resolver} (через сайт)", inline=False)
                await message.edit(embed=embed, view=None)
        except Exception:
            pass

    async def on_ready(self):
        print(f"[UnifiedBot] Вошёл как {self.user} | view_store: {self._view_store_size()} items")
        loop = asyncio.get_running_loop()
        try:
            await loop.run_in_executor(None, mysql_db.connect)
            print("[UnifiedBot] MySQL connection OK")
        except Exception as exc:
            print(f"[UnifiedBot] MySQL connection failed at startup: {exc} (will retry in background)")
        asyncio.create_task(self._mysql_poll_task())
        if not self.bridge_enabled:
            return
        # bridgebot: find channel, start message loop
        try:
            self._bridge_channel = discord.utils.get(
                self.guilds[0].text_channels, name=self.target_channel
            )
        except Exception:
            self._bridge_channel = None
        await self.wait_until_ready()
        while True:
            if self.pending_messages:
                await self.send_char_message(*self.pending_messages.pop(0))
            tickspeed = self.server.config.get("bridgebot", {}).get("tickspeed", 0.1)
            await asyncio.sleep(max(0.1, tickspeed))

    # ── on_message (bridgebot) ──

    async def on_message(self, message):
        if not self.bridge_enabled:
            return
        if message.author.bot or message.webhook_id is not None:
            return
        if message.channel != self._bridge_channel:
            return

        if message.content == "!getareas":
            msg = ""
            number_players = int(self.server.player_count)
            msg += f"**Clients in Areas**\n"
            for hub in self.server.hub_manager.hubs:
                if not hub.clients:
                    continue
                msg += f"**[={hub.name}=]**\n"
                for area in hub.areas:
                    if area.hidden:
                        continue
                    if not area.clients:
                        continue
                    msg += f"\t**[{area.id}] {area.name} (users: {len(area.clients)}) [{area.status}]"
                    if area.locked:
                        msg += f" [LOCKED]"
                    elif area.muted:
                        msg += f" [SPECTATABLE]"
                    if area.get_owners():
                        msg += f" [CM(s): {area.get_owners()}]"
                    msg += "**\n"
                    for client in area.clients:
                        if client.hidden:
                            continue
                        msg += "\t  \u25fe "
                        if client in area.afkers:
                            msg += "[AFK] "
                        if client.is_mod:
                            msg += "[M] "
                        elif client in area.area_manager.owners:
                            msg += "[GM] "
                        elif client in area._owners:
                            msg += "[CM] "
                        if client.showname != client.char_name:
                            msg += f'[{client.id}] "{client.showname}" ({client.char_name})'
                        else:
                            msg += f"[{client.id}] {client.showname}"
                        if client.pos:
                            msg += f" <{client.pos}> ({client.ipid})"
                        msg += "\n"
                msg += "\n"
            msg += f"Current online: {number_players} clients\n"
            if len(msg) > 2000:
                await self._bridge_channel.send(f"Current online: {number_players} clients\nArea information hidden due to char limit.")
            else:
                await self._bridge_channel.send(msg)
            return

        if message.content.startswith("$"):
            return

        try:
            max_char = int(self.server.config.get("max_chars_ic", 256))
        except Exception:
            max_char = 256
        if len(message.clean_content) > max_char:
            await self._bridge_channel.send(
                "Your message was too long - it was not received by the client. (The limit is 256 characters)"
            )
            return
        self.server.send_discord_chat(
            message.author.name,
            escape_markdown(message.clean_content),
            self.hub_id,
            self.area_id,
        )

    # ── bridgebot methods ──

    def queue_message(self, name, message, charname, anim):
        base = None
        avatar_url = None
        anim_url = None
        embed_emotes = False
        bridge_cfg = self.server.config.get("bridgebot", {})
        if "base_url" in bridge_cfg:
            base = bridge_cfg["base_url"]
        if "embed_emotes" in bridge_cfg:
            embed_emotes = bridge_cfg["embed_emotes"]
        if base is not None:
            avatar_url = base + parse.quote("characters/" + charname + "/char_icon.png")
            if embed_emotes:
                anim_url = base + parse.quote("characters/" + charname + "/" + anim + ".png")
        self.pending_messages.append([name, message, avatar_url, anim_url])

    async def send_char_message(self, name, message, avatar=None, image=None):
        if self._bridge_channel is None:
            return
        webhook = None
        embed = None
        try:
            webhooks = await self._bridge_channel.webhooks()
            for hook in webhooks:
                if hook.user == self.user or hook.name == "AO2_Bridgebot":
                    webhook = hook
                    break
            if webhook is None:
                webhook = await self._bridge_channel.create_webhook(name="AO2_Bridgebot")
            if image is not None:
                embed = discord.Embed()
                embed.set_image(url=image)
                print(avatar, image)
            await webhook.send(message, username=name, avatar_url=avatar, embed=embed)
            print(f'[UnifiedBot] Bridge: "{name}" -> "{self._bridge_channel.name}"')
        except Forbidden:
            print(f'[UnifiedBot] Bridge: недостаточно прав для "{name}: {message}"')
        except HTTPException:
            print(f'[UnifiedBot] Bridge: HTTP ошибка для "{name}: {message}"')
        except Exception as exc:
            print(f"[UnifiedBot] Bridge: {exc}")

    # ── whitelist: setup_hook ──

    async def _setup_whitelist_commands(self):
        if self._whitelist_registered:
            return
        self._whitelist_registered = True
        guild_obj = self._wl_guild_object()
        wl = app_commands.Group(name="wl", description="Вайт-лист сервера")

        @app_commands.describe(
            identifier="HDID, IPID или IP игрока",
            name="Никнейм на сервере (опционально)",
            discord_user="Дискорд-тег или ID (опционально)",
            entry_type="Тип идентификатора: hdid, ipid или ip",
        )
        @app_commands.choices(entry_type=[
            app_commands.Choice(name="HDID (аппаратный ID)", value="hdid"),
            app_commands.Choice(name="IPID (по IP)", value="ipid"),
            app_commands.Choice(name="IP (сырой адрес)", value="ip"),
        ])
        @wl.command(name="add", description="Добавить HDID, IPID или IP в вайт-лист")
        async def cmd_add(interaction: discord.Interaction, identifier: str,
                          name: Optional[str] = None, discord_user: Optional[str] = None,
                          entry_type: Optional[str] = "hdid"):
            if not await self._wl_check_admin(interaction):
                return
            etype = entry_type or "hdid"
            if wl_mod.add_entry(identifier.strip(), name or "", discord_user or "", str(interaction.user), etype):
                labels = {"hdid": "HDID", "ipid": "IPID", "ip": "IP"}
                await _safe_respond(interaction, f"\u2705 {labels.get(etype, etype.upper())} `{identifier}` добавлен в вайт-лист.")
            else:
                await _safe_respond(interaction, f"\u26a0\ufe0f Идентификатор `{identifier}` уже есть в вайт-листе.", ephemeral=True)

        @app_commands.describe(
            identifier="HDID или IPID",
            name="Новый никнейм (опционально)",
            discord_user="Новый дискорд (опционально)",
        )
        @wl.command(name="edit", description="Изменить ник/дискорд у записи")
        async def cmd_edit(interaction: discord.Interaction, identifier: str,
                           name: Optional[str] = None, discord_user: Optional[str] = None):
            if not await self._wl_check_admin(interaction):
                return
            if wl_mod.update_entry(identifier.strip(), name or "", discord_user or ""):
                await _safe_respond(interaction, f"\u2705 `{identifier}` обновлён.")
            else:
                await _safe_respond(interaction, f"\u26a0\ufe0f `{identifier}` не найден.", ephemeral=True)

        @app_commands.describe(identifier="HDID или IPID")
        @wl.command(name="remove", description="Удалить запись из вайт-листа")
        async def cmd_remove(interaction: discord.Interaction, identifier: str):
            if not await self._wl_check_admin(interaction):
                return
            if wl_mod.remove_entry(identifier.strip()):
                await _safe_respond(interaction, f"\u2705 `{identifier}` удалён из вайт-листа.")
            else:
                await _safe_respond(interaction, f"\u26a0\ufe0f `{identifier}` не найден.", ephemeral=True)

        @wl.command(name="list", description="Показать весь вайт-лист")
        async def cmd_list(interaction: discord.Interaction):
            if not await self._wl_check_admin(interaction):
                return
            all_entries = wl_mod.get_all()
            if not all_entries:
                await _safe_respond(interaction, "\U0001f4ed Вайт-лист пуст.")
                return
            view = WhitelistPaginator(all_entries, "\U0001f4cb Вайт-лист")
            await _safe_respond(interaction, embed=view._build_embed(), view=view)

        # ── wl gm subcommand group ──
        gm = app_commands.Group(name="gm", description="Управление доступом к /gm")

        @app_commands.describe(
            identifier="HDID, IPID или IP игрока",
            name="Никнейм (опционально)",
            discord_user="Дискорд (опционально)",
        )
        @gm.command(name="add", description="Добавить игрока в GM-доступ")
        async def cmd_gm_add(interaction: discord.Interaction, identifier: str,
                             name: Optional[str] = None, discord_user: Optional[str] = None):
            if not await self._wl_check_admin(interaction):
                return
            if wl_mod.gm_add(identifier.strip(), name or "", discord_user or "", str(interaction.user)):
                await _safe_respond(interaction, f"\u2705 `{identifier}` добавлен в GM-доступ.")
            else:
                await _safe_respond(interaction, f"\u26a0\ufe0f `{identifier}` уже в GM-доступе.", ephemeral=True)

        @app_commands.describe(identifier="HDID, IPID или IP")
        @gm.command(name="remove", description="Удалить игрока из GM-доступа")
        async def cmd_gm_remove(interaction: discord.Interaction, identifier: str):
            if not await self._wl_check_admin(interaction):
                return
            if wl_mod.gm_remove(identifier.strip()):
                await _safe_respond(interaction, f"\u2705 `{identifier}` удалён из GM-доступа.")
            else:
                await _safe_respond(interaction, f"\u26a0\ufe0f `{identifier}` не найден в GM-доступе.", ephemeral=True)

        @gm.command(name="list", description="Показать всех с GM-доступом")
        async def cmd_gm_list(interaction: discord.Interaction):
            if not await self._wl_check_admin(interaction):
                return
            entries = wl_mod.gm_get_all()
            if not entries:
                await _safe_respond(interaction, "\U0001f4ed GM-доступ пуст.")
                return
            view = WhitelistPaginator(entries, "\U0001f3af GM-доступ")
            await _safe_respond(interaction, embed=view._build_embed(), view=view)

        wl.add_command(gm)

        @wl.command(name="reload", description="Перезагрузить вайт-лист из файла")
        async def cmd_reload(interaction: discord.Interaction):
            if not await self._wl_check_admin(interaction):
                return
            wl_mod.reload_whitelist()
            await _safe_respond(interaction, f"\u2705 Вайт-лист перезагружен. Всего: {len(wl_mod.get_all())} записей.")

        @wl.command(name="export", description="Выгрузить whitelist.json файлом")
        async def cmd_export(interaction: discord.Interaction):
            if not await self._wl_check_admin(interaction):
                return
            data = wl_mod._whitelist_data
            hdids = len(data.get("hdids", {}))
            ipids = len(data.get("ipids", {}))
            ips = len(data.get("ips", {}))
            gm = len(data.get("gm_access", {}))
            total = hdids + ipids + ips
            f = discord.File(wl_mod.WL_PATH, filename="whitelist.json")
            await _safe_respond(
                interaction,
                f"\U0001f4e5 Экспортирован whitelist.json: HDID: {hdids}, IPID: {ipids}, IP: {ips}, GM: {gm}, Всего: {total}",
                file=f,
            )

        self.tree.add_command(wl)
        if guild_obj is not None:
            self.tree.add_command(wl, guild=guild_obj)

    # ── whitelist: helpers ──

    def _wl_cfg(self):
        return self.server.config.get("whitelist", {})

    def _wl_guild_object(self):
        guild_id = self._wl_cfg().get("guild_id")
        if guild_id:
            try:
                return discord.Object(id=int(guild_id))
            except (TypeError, ValueError):
                pass
        return None

    async def _wl_find_channel(self, channel_id, channel_name):
        channel = None
        if channel_id:
            try:
                cid = int(channel_id)
                channel = self.get_channel(cid)
                if channel is None:
                    channel = await self.fetch_channel(cid)
            except (TypeError, ValueError, Exception):
                pass
        if channel is None and channel_name:
            for guild in self.guilds:
                found = discord.utils.get(guild.text_channels, name=channel_name)
                if found:
                    channel = found
                    break
        return channel

    async def _wl_check_admin(self, interaction: discord.Interaction) -> bool:
        if not self._wl_is_admin(interaction.user):
            await _safe_respond(interaction, "\u274c У вас нет прав для этой команды.", ephemeral=True)
            return False
        return True

    def _wl_is_admin(self, member) -> bool:
        cfg = self._wl_cfg()
        admin_user_id = cfg.get("admin_user_id")
        admin_role_id = cfg.get("admin_role_id")
        if admin_user_id:
            try:
                if getattr(member, "id", None) == int(admin_user_id):
                    return True
            except (TypeError, ValueError):
                pass
        if admin_role_id:
            try:
                role_id = int(admin_role_id)
                if any(getattr(r, "id", None) == role_id for r in getattr(member, "roles", [])):
                    return True
            except (TypeError, ValueError):
                pass
        perms = getattr(member, "guild_permissions", None)
        return bool(perms and (perms.administrator or perms.manage_guild))

    # ── whitelist: join attempt flow ──

    async def notify_join_attempt(self, client, hdid: str, ipid: str = "", ip_address: str = ""):
        if not self.whitelist_enabled:
            return
        cooldown_key = ip_address or ipid or hdid
        if not wl_mod.can_notify(cooldown_key):
            return
        cfg = self._wl_cfg()
        channel = await self._wl_find_channel(cfg.get("request_channel_id"), cfg.get("request_channel"))
        if channel is None:
            return
        request_id = f"wl-{int(time.time() * 1000)}-{client.id}"
        embed = discord.Embed(
            title="\U0001f514 Попытка подключения не-вайтлистенного игрока",
            description="Игрок с неизвестным HDID/IPID/IP попытался подключиться к серверу.",
            color=discord.Color.orange(),
        )
        embed.add_field(name="HDID", value=f"`{hdid}`", inline=False)
        embed.add_field(name="IPID", value=f"`{ipid}`", inline=True)
        embed.add_field(name="IP", value=f"`{ip_address}`", inline=True)
        embed.set_footer(text="Нажмите \u00abДобавить\u00bb, чтобы внести игрока в вайт-лист.")
        view = JoinAttemptView(self, request_id, hdid)
        message = await channel.send(embed=embed, view=view)
        self.whitelist_pending_requests[request_id] = {
            "hdid": hdid,
            "ipid": ipid,
            "ip": ip_address,
            "message_id": message.id,
            "channel_id": channel.id,
        }
        try:
            loop = asyncio.get_running_loop()
            await loop.run_in_executor(None, mysql_db.store_pending, request_id, "wl_join", {
                "hdid": hdid,
                "ipid": ipid,
                "ip": ip_address,
                "channel_id": channel.id,
                "message_id": message.id,
            })
        except Exception as exc:
            print(f"[UnifiedBot] MySQL store wl_join error: {exc}")

    async def _wl_resolve_join_attempt(self, request_id: str, hdid: str, approved: bool, resolver: str,
                                       name: str = "", discord_tag: str = ""):
        request = self.whitelist_pending_requests.pop(request_id, None)
        if request is None:
            return
        try:
            channel = self.get_channel(request["channel_id"]) or await self.fetch_channel(request["channel_id"])
            if channel is not None:
                message = await channel.fetch_message(request["message_id"])
                embed = message.embeds[0] if message.embeds else None
                if embed:
                    if approved:
                        embed.color = discord.Color.green()
                        status_parts = [f"\u2705 Добавлен администратором {resolver}"]
                        if name:
                            status_parts.append(f"\U0001f7e0 Ник: {name}")
                        if discord_tag:
                            status_parts.append(f"\U0001f4ac Discord: {discord_tag}")
                        embed.add_field(name="Статус", value="\n".join(status_parts), inline=False)
                    else:
                        embed.color = discord.Color.red()
                        embed.add_field(name="\u274c Статус", value=f"Отклонён администратором {resolver}", inline=False)
                    await message.edit(embed=embed, view=None)
        except Exception:
            pass

    # ── login_approver: setup_hook ──

    async def _setup_login_commands(self):
        if self._adminka_registered:
            return
        guild_obj = self._login_guild_object()
        command = app_commands.Command(
            name="adminka",
            description="Открыть админ-панель сервера",
            callback=self._adminka_command,
        )
        if guild_obj is not None:
            self.tree.add_command(command, guild=guild_obj)
        else:
            self.tree.add_command(command)
        self._adminka_registered = True

    # ── login_approver: helpers ──

    def _login_cfg(self):
        return self.server.config.get("login_approval", {}) or {}

    def _login_guild_object(self):
        guild_id = _safe_int(self._login_cfg().get("guild_id"))
        if guild_id:
            return discord.Object(id=guild_id)
        return None

    def _login_approval_channel_candidates(self):
        cfg = self._login_cfg()
        return [_safe_int(cfg.get("channel_id")), cfg.get("channel")]

    def _login_panel_channel_candidates(self):
        cfg = self._login_cfg()
        return [
            _safe_int(cfg.get("admin_panel_channel_id")),
            cfg.get("admin_panel_channel"),
            _safe_int(cfg.get("channel_id")),
            cfg.get("channel"),
        ]

    def _login_panel_category_candidates(self):
        cfg = self._login_cfg()
        return [_safe_int(cfg.get("admin_panel_category_id")), cfg.get("admin_panel_category")]

    async def _login_resolve_text_channel(self, channel_id, channel_name) -> Optional[discord.abc.Messageable]:
        channel = None
        if channel_id:
            channel = self.get_channel(channel_id)
            if channel is None:
                try:
                    channel = await self.fetch_channel(channel_id)
                except Exception:
                    channel = None
        if channel is None and channel_name:
            for guild in self.guilds:
                found = discord.utils.get(guild.text_channels, name=channel_name)
                if found is not None:
                    channel = found
                    break
        return channel

    async def get_approval_channel(self):
        channel_id, channel_name = self._login_approval_channel_candidates()
        return await self._login_resolve_text_channel(channel_id, channel_name)

    async def get_panel_channel(self):
        channel_id, channel_name, fallback_id, fallback_name = self._login_panel_channel_candidates()
        channel = await self._login_resolve_text_channel(channel_id, channel_name)
        if channel is None:
            channel = await self._login_resolve_text_channel(fallback_id, fallback_name)
        return channel

    def panel_location_allowed(self, channel) -> bool:
        if channel is None:
            return False
        panel_channel_id, panel_channel_name, fallback_id, fallback_name = self._login_panel_channel_candidates()
        category_id, category_name = self._login_panel_category_candidates()
        matches = []
        if panel_channel_id:
            matches.append(getattr(channel, "id", None) == panel_channel_id)
        if panel_channel_name:
            matches.append(getattr(channel, "name", None) == panel_channel_name)
        if category_id:
            matches.append(getattr(channel, "category_id", None) == category_id)
        if category_name:
            category = getattr(channel, "category", None)
            matches.append(category is not None and getattr(category, "name", None) == category_name)
        if not matches:
            if fallback_id:
                matches.append(getattr(channel, "id", None) == fallback_id)
            if fallback_name:
                matches.append(getattr(channel, "name", None) == fallback_name)
        return any(matches) if matches else True

    def is_admin_member(self, member, specific_user_id: Optional[int] = None) -> bool:
        if member is None:
            return False
        if specific_user_id is not None:
            return getattr(member, "id", None) == specific_user_id
        cfg = self._login_cfg()
        admin_user_id = _safe_int(cfg.get("admin_user_id"))
        admin_role_id = _safe_int(cfg.get("admin_role_id"))
        if admin_user_id and getattr(member, "id", None) == admin_user_id:
            return True
        if admin_role_id and any(getattr(role, "id", None) == admin_role_id for role in getattr(member, "roles", [])):
            return True
        perms = getattr(member, "guild_permissions", None)
        return bool(perms and (perms.administrator or perms.manage_guild))

    async def ensure_panel_access(self, interaction: discord.Interaction) -> bool:
        if not self.panel_location_allowed(interaction.channel):
            await _safe_respond(
                interaction,
                "Эта команда доступна только в настроенном канале/категории админки.",
                ephemeral=True,
            )
            return False
        if not self.is_admin_member(interaction.user):
            await _safe_respond(interaction, "У тебя нет доступа к этой панели.", ephemeral=True)
            return False
        return True

    # ── login_approver: adminka command ──

    async def _adminka_command(self, interaction: discord.Interaction):
        if not await self.ensure_panel_access(interaction):
            return
        await interaction.response.send_message(
            embed=self.build_admin_panel_embed(),
            view=AdminRootView(self),
            ephemeral=False,
        )

    # ── login_approver: request flow ──

    async def submit_login_request(self, client, approver_id: Optional[int] = None) -> bool:
        if not self.login_enabled:
            return False
        channel = await self.get_approval_channel()
        if channel is None:
            return False

        client_key = id(client)
        if client_key in self.pending_by_client:
            return True

        request_id = f"login-{int(time.time() * 1000)}-{client.id}"
        request = PendingLoginRequest(
            request_id=request_id,
            client=client,
            approver_id=approver_id,
            channel_id=channel.id,
        )
        self.login_pending_requests[request_id] = request
        self.pending_by_client[client_key] = request_id

        mention = self._build_login_mention(approver_id)
        view = LoginApprovalView(self, request_id)
        message = await channel.send(
            content=mention or None,
            embed=self.build_login_request_embed(client, approver_id),
            view=view,
            allowed_mentions=discord.AllowedMentions(
                users=True,
                roles=True,
                everyone=bool(self._login_cfg().get("mention_here", False)),
            ),
        )
        request.message_id = message.id
        timeout = max(10, int(self._login_cfg().get("request_timeout", 300)))
        request.timeout_task = asyncio.create_task(self._expire_request_after(request_id, timeout))
        try:
            loop = asyncio.get_running_loop()
            await loop.run_in_executor(None, mysql_db.store_pending, request_id, "login_approval", {
                "client_id": client.id,
                "approver_id": approver_id,
                "channel_id": channel.id,
                "message_id": message.id,
            })
        except Exception as exc:
            print(f"[UnifiedBot] MySQL store login_approval error: {exc}")
        return True

    def _build_login_mention(self, approver_id: Optional[int]) -> str:
        cfg = self._login_cfg()
        if approver_id:
            return f"<@{approver_id}>"
        admin_user_id = _safe_int(cfg.get("admin_user_id"))
        admin_role_id = _safe_int(cfg.get("admin_role_id"))
        if admin_user_id:
            return f"<@{admin_user_id}>"
        if admin_role_id:
            return f"<@&{admin_role_id}>"
        if cfg.get("mention_here"):
            return "@here"
        return ""

    def _request_approver_label(self, approver_id: Optional[int]) -> str:
        if approver_id:
            return f"<@{approver_id}>"
        cfg = self._login_cfg()
        admin_user_id = _safe_int(cfg.get("admin_user_id"))
        admin_role_id = _safe_int(cfg.get("admin_role_id"))
        if admin_user_id:
            return f"<@{admin_user_id}>"
        if admin_role_id:
            return f"<@&{admin_role_id}>"
        if cfg.get("mention_here"):
            return "@here"
        return "Администратор сервера"

    def build_login_request_embed(
        self, client, approver_id: Optional[int],
        status: str = "pending",
        resolver_name: Optional[str] = None,
        reason: Optional[str] = None,
    ) -> discord.Embed:
        status_map = {
            "pending": ("Ожидает подтверждения", discord.Color.orange()),
            "approved": ("Авторизация подтверждена", discord.Color.green()),
            "rejected": ("Авторизация отклонена", discord.Color.red()),
            "expired": ("Истёк таймаут ожидания", discord.Color.dark_grey()),
        }
        status_text, color = status_map.get(status, status_map["pending"])
        embed = discord.Embed(
            title="Запрос на вход в модерку",
            description="Игрок запросил авторизацию модератора через `/login`.",
            color=color,
        )
        embed.add_field(name="Игрок", value=f"`{getattr(client, 'name', '-')}`", inline=True)
        embed.add_field(name="Showname", value=f"`{getattr(client, 'showname', '-')}`", inline=True)
        embed.add_field(name="Персонаж", value=f"`{getattr(client, 'char_name', '-')}`", inline=True)
        embed.add_field(name="ID / IPID", value=f"`{getattr(client, 'id', '-')}` / `{getattr(client, 'ipid', '-')}`", inline=True)
        embed.add_field(name="HDID", value=f"`{getattr(client, 'hdid', '-')}`", inline=False)
        try:
            area_text = (
                f"Hub [{client.area.area_manager.id}] {client.area.area_manager.name} -> "
                f"[{client.area.id}] {client.area.name}"
            )
        except Exception:
            area_text = "-"
        embed.add_field(name="Локация", value=area_text, inline=False)
        embed.add_field(name="Кто подтверждает", value=self._request_approver_label(approver_id), inline=False)
        embed.add_field(name="Статус", value=status_text, inline=False)
        if resolver_name:
            embed.add_field(name="Решение принял", value=resolver_name, inline=False)
        if reason:
            embed.add_field(name="Причина отказа", value=reason, inline=False)
        return embed

    def build_admin_panel_embed(self) -> discord.Embed:
        embed = discord.Embed(title="Discord-админка сервера", color=discord.Color.blurple())
        embed.add_field(
            name="Разделы",
            value=(
                "**Состояние сервера** — игроки, хабы, live-логи.\n"
                "**Управление игроками** — модераторские действия по игрокам.\n"
                "**Команды** — быстрые админ-команды и рассылки."
            ),
            inline=False,
        )
        panel_channel_id, panel_channel_name, fallback_id, fallback_name = self._login_panel_channel_candidates()
        category_id, category_name = self._login_panel_category_candidates()
        footer_bits = []
        if panel_channel_id:
            footer_bits.append(f"канал ID {panel_channel_id}")
        elif panel_channel_name:
            footer_bits.append(f"канал #{panel_channel_name}")
        elif fallback_id:
            footer_bits.append(f"канал ID {fallback_id}")
        elif fallback_name:
            footer_bits.append(f"канал #{fallback_name}")
        if category_id:
            footer_bits.append(f"категория ID {category_id}")
        elif category_name:
            footer_bits.append(f"категория {category_name}")
        if footer_bits:
            embed.set_footer(text="Работает только: " + ", ".join(footer_bits))
        return embed

    def build_server_state_embed(self) -> discord.Embed:
        embed = discord.Embed(title="Состояние сервера", color=discord.Color.blue())
        embed.add_field(name="Игроки", value="Показывает игроков сразу по всем хабам через `/gethubs`.", inline=False)
        embed.add_field(name="Хабы", value="Показывает список хабов через `/hub` без аргументов.", inline=False)
        embed.add_field(name="Логи", value="Открывает live-просмотр `server.log` с автообновлением.", inline=False)
        return embed

    def build_player_management_embed(self) -> discord.Embed:
        embed = discord.Embed(title="Управление игроками", color=discord.Color.red())
        embed.add_field(name="Бан", value="Открывает форму для `/banhdid`. Аргументы вводятся так же, как в игре.", inline=False)
        return embed

    def build_commands_embed(self) -> discord.Embed:
        embed = discord.Embed(title="Команды", color=discord.Color.green())
        embed.add_field(name="ООС-сообщение", value="Отправляет OOC-сообщение от имени администратора с указанным ником.", inline=False)
        return embed

    # ── login_approver: expiry ──

    async def _expire_request_after(self, request_id: str, timeout: int):
        await asyncio.sleep(timeout)
        request = self.login_pending_requests.get(request_id)
        if request is None or request.resolved:
            return
        request.resolved = True
        client = request.client
        if client in self.server.client_manager.clients:
            client.send_ooc("Время ожидания подтверждения в Discord истекло. Используйте /login ещё раз.")
        try:
            channel = self.get_channel(request.channel_id) or await self.fetch_channel(request.channel_id)
        except Exception:
            channel = None
        if channel is not None and request.message_id is not None:
            try:
                message = await channel.fetch_message(request.message_id)
                await message.edit(
                    content=self._build_login_mention(request.approver_id) or None,
                    embed=self.build_login_request_embed(client, request.approver_id, status="expired"),
                    view=LoginApprovalView(self, request_id, disabled=True),
                )
            except Exception:
                pass
        self.pending_by_client.pop(id(client), None)
        self.login_pending_requests.pop(request_id, None)

    async def resolve_login_request(self, request_id: str, approved: bool, resolver_name: str, reason: Optional[str] = None):
        request = self.login_pending_requests.get(request_id)
        if request is None or request.resolved:
            return False
        request.resolved = True
        client = request.client
        if request.timeout_task is not None:
            request.timeout_task.cancel()

        try:
            channel = self.get_channel(request.channel_id) or await self.fetch_channel(request.channel_id)
        except Exception:
            channel = None
        if channel is not None and request.message_id is not None:
            try:
                message = await channel.fetch_message(request.message_id)
                await message.edit(
                    content=self._build_login_mention(request.approver_id) or None,
                    embed=self.build_login_request_embed(
                        client, request.approver_id,
                        status=("approved" if approved else "rejected"),
                        resolver_name=resolver_name,
                        reason=reason,
                    ),
                    view=LoginApprovalView(self, request_id, disabled=True),
                )
            except Exception:
                pass

        if client in self.server.client_manager.clients:
            if approved:
                client.failed_attempts = 0
                client.is_mod = True
                client.mod_profile_name = DISCORD_LOGIN_PROFILE
                client.area.broadcast_area_list(client)
                client.area.broadcast_evidence_list()
                client.send_ooc("Logged in as a moderator.")
                self.server.webhooks.login(client, DISCORD_LOGIN_PROFILE)
                database.log_misc("login", client, data={"profile": DISCORD_LOGIN_PROFILE})
            else:
                deny_message = "Вход в модерку был отклонён."
                if reason:
                    deny_message += f" Причина: {reason}"
                client.send_ooc(deny_message)

        self.pending_by_client.pop(id(client), None)
        self.login_pending_requests.pop(request_id, None)
        return True

    async def cancel_login_for_client(self, client, reason: str = "Игрок отключился."):
        request_id = self.pending_by_client.get(id(client))
        if not request_id:
            return
        await self.resolve_login_request(request_id, approved=False, resolver_name="Система", reason=reason)

    # ── login_approver: log stream ──

    def build_log_embed(self, body: str, interval: int, timeout: int, started: Optional[float] = None, stopped: bool = False) -> discord.Embed:
        elapsed = int(time.time() - started) if started is not None else 0
        title = "Live server.log" if not stopped else "Live server.log остановлен"
        color = discord.Color.orange() if not stopped else discord.Color.dark_grey()
        embed = discord.Embed(title=title, color=color)
        embed.description = f"```log\n{body or '(лог пуст или не найден)'}\n```"
        embed.add_field(name="Обновление", value=f"каждые {interval} сек.", inline=True)
        embed.add_field(name="Автостоп", value=f"{timeout} сек.", inline=True)
        embed.add_field(name="Прошло", value=f"{elapsed} сек.", inline=True)
        return embed

    async def open_live_logs(self, interaction: discord.Interaction):
        log_path = self._login_cfg().get("admin_panel_log_path", "logs/server.log")
        interval = max(1, int(self._login_cfg().get("log_update_interval", 3)))
        timeout = max(interval, int(self._login_cfg().get("log_stream_timeout", 300)))
        initial = self._build_log_payload(log_path, interval, timeout)
        message = await interaction.channel.send(
            embed=self.build_log_embed(initial, interval, timeout),
            view=LogStreamView(self),
        )
        session = LogStreamSession(message=message, owner_id=interaction.user.id, last_payload=initial)
        self.log_streams[message.id] = session
        session.task = asyncio.create_task(self._run_log_stream(session, log_path, interval, timeout))
        await _safe_respond(interaction, f"Live-логи открыты: {message.jump_url}", ephemeral=True)

    async def stop_log_stream(self, message_id: int, note: str = "\u23f9 Live-лог остановлен."):
        session = self.log_streams.get(message_id)
        if session is None:
            return
        session.stop_event.set()
        if session.task is not None:
            session.task.cancel()
        interval = max(1, int(self._login_cfg().get("log_update_interval", 3)))
        timeout = max(interval, int(self._login_cfg().get("log_stream_timeout", 300)))
        try:
            await session.message.edit(
                content=note,
                embed=self.build_log_embed(session.last_payload, interval, timeout, stopped=True),
                view=LogStreamView(self, disabled=True),
            )
        except Exception:
            pass
        self.log_streams.pop(message_id, None)

    async def _run_log_stream(self, session: LogStreamSession, log_path: str, interval: int, timeout: int):
        started = time.time()
        try:
            while not session.stop_event.is_set():
                payload = self._build_log_payload(log_path, interval, timeout)
                if payload != session.last_payload:
                    session.last_payload = payload
                    try:
                        await session.message.edit(
                            content=None,
                            embed=self.build_log_embed(payload, interval, timeout, started=started),
                            view=LogStreamView(self),
                        )
                    except Exception:
                        break
                if time.time() - started >= timeout:
                    break
                await asyncio.sleep(interval)
        except asyncio.CancelledError:
            return
        finally:
            if session.message.id in self.log_streams:
                try:
                    await session.message.edit(
                        content="\u23f9 Live-лог остановлен.",
                        embed=self.build_log_embed(session.last_payload, interval, timeout, started=started, stopped=True),
                        view=LogStreamView(self, disabled=True),
                    )
                except Exception:
                    pass
                self.log_streams.pop(session.message.id, None)

    def _build_log_payload(self, log_path: str, interval: int, timeout: int, started: Optional[float] = None) -> str:
        max_chars = max(300, int(self._login_cfg().get("log_max_chars", 1500)))
        tail_lines = max(10, int(self._login_cfg().get("log_tail_lines", 40)))
        text = tail_file(log_path, tail_lines=tail_lines, max_chars=max_chars)
        if not text:
            text = "(лог пуст или не найден)"
        return text

    async def _run_sync_on_server_thread(self, func, *args, **kwargs):
        server_loop = getattr(self.server, "loop", None)
        try:
            current_loop = asyncio.get_running_loop()
        except RuntimeError:
            current_loop = None
        if server_loop is None or server_loop is current_loop:
            return func(*args, **kwargs)
        future = concurrent.futures.Future()

        def runner():
            try:
                future.set_result(func(*args, **kwargs))
            except Exception as exc:
                future.set_exception(exc)

        server_loop.call_soon_threadsafe(runner)
        return await asyncio.wrap_future(future)

    async def execute_existing_command(self, member, command_name: str, arg: str = "") -> str:
        return await self._run_sync_on_server_thread(self._execute_existing_command_sync, member, command_name, arg)

    def _execute_existing_command_sync(self, member, command_name: str, arg: str = "") -> str:
        proxy = DiscordCommandProxy(self.server, member)
        try:
            ao_commands.call(proxy, command_name, arg)
        except (ClientError, ArgumentError, ServerError) as exc:
            proxy.send_ooc(f"Ошибка при выполнении /{command_name}: {exc}")
        except Exception as exc:
            proxy.send_ooc(f"Внутренняя ошибка при выполнении /{command_name}: {exc}")
            traceback.print_exc()
        output = proxy.get_output()
        return output or f"/{command_name} выполнена без текстового ответа."

    async def send_admin_ooc(self, member, nickname: str, message: str) -> str:
        return await self._run_sync_on_server_thread(self._send_admin_ooc_sync, member, nickname, message)

    def _send_admin_ooc_sync(self, member, nickname: str, message: str) -> str:
        nickname = (nickname or "").strip()
        if not nickname:
            nickname = getattr(member, "display_name", None) or getattr(member, "name", None) or "Admin"
        prefix = str(self._login_cfg().get("admin_ooc_prefix", "[M] "))
        display_name = f"{prefix}{nickname}" if prefix else nickname
        self.server.send_all_cmd_pred("CT", display_name, message, "1")
        try:
            database.log_misc(
                "discord.admin_ooc",
                data={
                    "discord_user_id": getattr(member, "id", None),
                    "discord_user": str(member) if member is not None else None,
                    "display_name": display_name,
                    "message": message,
                },
            )
        except Exception:
            traceback.print_exc()
        return f"ООС-сообщение отправлено от имени `{display_name}`."


    # ── gm request flow ──

    async def submit_gm_request(self, client, arg: str, cmd: str):
        if not self.login_enabled:
            if getattr(client, "send_ooc", None):
                client.send_ooc("Discord-бот не запущен. Невозможно отправить запрос.")
            return
        channel = await self.get_approval_channel()
        if channel is None:
            if getattr(client, "send_ooc", None):
                client.send_ooc("Канал подтверждения не настроен.")
            return

        request_id = f"gm-{int(time.time() * 1000)}-{client.id}"
        embed = discord.Embed(
            title="\u2699\ufe0f Запрос на выдачу GM",
            description=f"Игрок хочет выполнить команду {cmd}.",
            color=discord.Color.orange(),
        )
        embed.add_field(name="Игрок", value=f"`{getattr(client, 'showname', '-')} [{client.id}]`", inline=True)
        embed.add_field(name="Зона", value=f"`{getattr(client, 'area', None) and client.area.name or '-'}`", inline=True)
        embed.add_field(name="Хаб", value=f"`Hub [{getattr(client, 'area', None) and client.area.area_manager.id or '?'}]`", inline=True)
        embed.add_field(name="Аргументы", value=f"`{arg or '(пусто)'}`", inline=False)
        embed.add_field(name="HDID", value=f"`{getattr(client, 'hdid', '-')}`", inline=True)
        embed.add_field(name="IPID", value=f"`{getattr(client, 'ipid', '-')}`", inline=True)
        embed.set_footer(text="Нажмите «Подтвердить» или «Отклонить».")

        view = GMRequestView(self, request_id, client, arg, cmd)
        message = await channel.send(embed=embed, view=view)
        self.gm_pending_requests[request_id] = {
            "client": client,
            "arg": arg,
            "cmd": cmd,
            "message_id": message.id,
            "channel_id": channel.id,
        }
        try:
            loop = asyncio.get_running_loop()
            await loop.run_in_executor(None, mysql_db.store_pending, request_id, "gm_request", {
                "client_id": client.id,
                "client_name": getattr(client, "showname", "?"),
                "arg": arg,
                "cmd": cmd,
                "channel_id": channel.id,
                "message_id": message.id,
            })
        except Exception as exc:
            print(f"[UnifiedBot] MySQL store gm_request error: {exc}")
        if getattr(client, "send_ooc", None):
            client.send_ooc(f"Ваш запрос на выполнение {cmd} отправлен администраторам. Ожидайте ответа.")

    async def _resolve_gm_request(self, request_id: str, approved: bool, resolver_name: str, reason: str = ""):
        request = self.gm_pending_requests.pop(request_id, None)
        if request is None:
            return
        client = request["client"]
        arg = request["arg"]
        cmd = request["cmd"]
        try:
            channel = self.get_channel(request["channel_id"]) or await self.fetch_channel(request["channel_id"])
            if channel is not None:
                try:
                    message = await channel.fetch_message(request["message_id"])
                    embed = message.embeds[0] if message.embeds else None
                    if embed:
                        if approved:
                            embed.color = discord.Color.green()
                            embed.add_field(name="\u2705 Статус", value=f"Одобрен {resolver_name}", inline=False)
                        else:
                            embed.color = discord.Color.red()
                            embed.add_field(name="\u274c Статус", value=f"Отклонён {resolver_name}", inline=False)
                        if reason:
                            embed.add_field(name="Комментарий", value=reason, inline=False)
                        await message.edit(embed=embed, view=None)
                except Exception:
                    pass
        except Exception:
            pass

        if client in self.server.client_manager.clients:
            if approved:
                ooc_cmd_gm_execute(client, arg)
                client.send_ooc(f"Ваш запрос на {cmd} одобрен администратором {resolver_name}.")
                if reason:
                    client.send_ooc(f"Комментарий: {reason}")
            else:
                msg = f"Ваш запрос на {cmd} отклонён администратором {resolver_name}."
                if reason:
                    msg += f" Причина: {reason}"
                client.send_ooc(msg)

    async def _gm_request_cleanup_for_client(self, client):
        for request_id, request in list(self.gm_pending_requests.items()):
            if request.get("client") is client:
                self.gm_pending_requests.pop(request_id, None)
                return


# ═══════════════════════════════════════════════════════════
# VIEW CLASSES
# ═══════════════════════════════════════════════════════════

# ── Whitelist Views ──

class AddWhitelistModal(discord.ui.Modal, title="Добавить в вайт-лист"):
    name = discord.ui.TextInput(label="Никнейм (опционально)", required=False, max_length=64)
    discord_user = discord.ui.TextInput(label="Discord (опционально)", required=False, max_length=64)

    def __init__(self, bot: UnifiedBot, request_id: str, request_data: dict):
        super().__init__()
        self.bot = bot
        self.request_id = request_id
        self.request_data = request_data

    async def on_submit(self, interaction: discord.Interaction):
        try:
            hdid = self.request_data.get("hdid", "")
            ipid = self.request_data.get("ipid", "")
            ip = self.request_data.get("ip", "")
            name_val = (self.name.value or "").strip()
            discord_val = (self.discord_user.value or "").strip()
            resolver = str(interaction.user)

            print(f"[UnifiedBot] AddWhitelistModal: hdid='{hdid}' ipid='{ipid}' ip='{ip}' name='{name_val}'")

            if not hdid and not ipid and not ip:
                await _safe_respond(interaction, "\u26a0\ufe0f Данные запроса не найдены. Возможно, запрос устарел.", ephemeral=True)
                return

            added_any = False
            labels = []

            if hdid:
                if wl_mod.add_entry(hdid, name_val, discord_val, resolver, "hdid"):
                    labels.append("HDID")
                    added_any = True
                else:
                    print(f"[UnifiedBot] HDID {hdid} уже был в вайт-листе")
            if ipid:
                if wl_mod.add_entry(ipid, name_val, discord_val, resolver, "ipid"):
                    labels.append("IPID")
                    added_any = True
                else:
                    print(f"[UnifiedBot] IPID {ipid} уже был в вайт-листе")
            if ip:
                if wl_mod.add_entry(ip, name_val, discord_val, resolver, "ip"):
                    labels.append("IP")
                    added_any = True
                else:
                    print(f"[UnifiedBot] IP {ip} уже был в вайт-листе")

            if added_any:
                await self.bot._wl_resolve_join_attempt(self.request_id, hdid, True, resolver, name_val, discord_val)
                await _safe_respond(interaction, f"\u2705 Добавлено: {', '.join(labels)}.", ephemeral=True)
            else:
                await _safe_respond(interaction, "\u26a0\ufe0f Все идентификаторы уже были в вайт-листе.", ephemeral=True)
        except Exception as exc:
            print(f"[UnifiedBot] AddWhitelistModal ERROR: {type(exc).__name__}: {exc}")
            traceback.print_exc()
            try:
                await _safe_respond(interaction, "\u274c Ошибка при добавлении.", ephemeral=True)
            except Exception:
                pass


class JoinAttemptView(discord.ui.View):
    def __init__(self, bot: UnifiedBot, request_id: str, hdid: str):
        super().__init__(timeout=None)
        self.bot = bot
        self.request_id = request_id
        self.hdid = hdid
        self._timeout_task = asyncio.create_task(self._auto_timeout())

    async def _auto_timeout(self):
        await asyncio.sleep(1800)
        try:
            await self.bot._wl_resolve_join_attempt(self.request_id, self.hdid, False, "по тайм-ауту (30 мин)")
            self.stop()
        except Exception:
            pass

    async def interaction_check(self, interaction: discord.Interaction) -> bool:
        adm = self.bot._wl_is_admin(interaction.user)
        print(f"[UnifiedBot] JoinAttemptView check: user={interaction.user} is_admin={adm}")
        if not adm:
            try:
                await interaction.response.send_message("\u274c У вас нет прав.", ephemeral=True)
            except Exception as e:
                print(f"[UnifiedBot] JoinAttemptView check send ERROR: {e}")
                try:
                    await interaction.followup.send("\u274c У вас нет прав.", ephemeral=True)
                except Exception:
                    pass
            return False
        return True

    async def on_error(self, interaction: discord.Interaction, error: Exception, item):
        print(f"[UnifiedBot] JoinAttemptView on_error: {type(error).__name__}: {error}")
        traceback.print_exc()
        try:
            if not interaction.response.is_done():
                await interaction.response.defer(ephemeral=True)
            await interaction.followup.send("\u274c Произошла ошибка.", ephemeral=True)
        except Exception:
            pass

    @discord.ui.button(label="\u2705 Добавить в вайт-лист", style=discord.ButtonStyle.success)
    async def approve(self, interaction: discord.Interaction, button: discord.ui.Button):
        print(f"[UnifiedBot] JoinAttemptView.approve. user={interaction.user} request={self.request_id}")
        try:
            request = self.bot.whitelist_pending_requests.get(self.request_id, {})
            await interaction.response.send_modal(AddWhitelistModal(self.bot, self.request_id, request))
        except Exception as exc:
            print(f"[UnifiedBot] JoinAttemptView.approve ERROR: {type(exc).__name__}: {exc}")
            traceback.print_exc()
            try:
                if not interaction.response.is_done():
                    await interaction.response.defer(ephemeral=True)
                await interaction.followup.send("\u274c Ошибка.", ephemeral=True)
            except Exception:
                pass

    @discord.ui.button(label="\u274c Отклонить", style=discord.ButtonStyle.danger)
    async def reject(self, interaction: discord.Interaction, button: discord.ui.Button):
        print(f"[UnifiedBot] JoinAttemptView.reject. user={interaction.user} request={self.request_id}")
        try:
            await interaction.response.defer(ephemeral=True)
            await self.bot._wl_resolve_join_attempt(self.request_id, self.hdid, False, str(interaction.user))
            await interaction.followup.send(f"\u274c Запрос на `{self.hdid}` отклонён.", ephemeral=True)
        except Exception as exc:
            print(f"[UnifiedBot] JoinAttemptView.reject ERROR: {type(exc).__name__}: {exc}")
            traceback.print_exc()
            try:
                if not interaction.response.is_done():
                    await interaction.response.defer(ephemeral=True)
                await interaction.followup.send("\u274c Ошибка.", ephemeral=True)
            except Exception:
                pass


# ── GM Request Views ──

class GMRequestView(discord.ui.View):
    def __init__(self, bot: UnifiedBot, request_id: str, client, arg: str, cmd: str):
        super().__init__(timeout=None)
        self.bot = bot
        self.request_id = request_id
        self.client = client
        self.arg = arg
        self.cmd = cmd

    async def interaction_check(self, interaction: discord.Interaction) -> bool:
        adm = self.bot.is_admin_member(interaction.user)
        print(f"[UnifiedBot] GMRequestView check: user={interaction.user} is_admin={adm}")
        if not adm:
            try:
                await interaction.response.send_message("\u274c У вас нет прав.", ephemeral=True)
            except Exception as e:
                print(f"[UnifiedBot] GMRequestView check ERROR: {e}")
                try:
                    await interaction.followup.send("\u274c У вас нет прав.", ephemeral=True)
                except Exception:
                    pass
            return False
        return True

    async def on_error(self, interaction: discord.Interaction, error: Exception, item):
        print(f"[UnifiedBot] GMRequestView on_error: {type(error).__name__}: {error}")
        traceback.print_exc()
        try:
            if not interaction.response.is_done():
                await interaction.response.defer(ephemeral=True)
            await interaction.followup.send("\u274c Произошла ошибка.", ephemeral=True)
        except Exception:
            pass

    @discord.ui.button(label="\u2705 Подтвердить", style=discord.ButtonStyle.success)
    async def approve(self, interaction: discord.Interaction, button: discord.ui.Button):
        print(f"[UnifiedBot] GMRequestView.approve. user={interaction.user} request={self.request_id}")
        try:
            await interaction.response.defer(ephemeral=True)
            await self.bot._resolve_gm_request(self.request_id, True, str(interaction.user))
            await interaction.followup.send(f"\u2705 Запрос {self.cmd} одобрен.", ephemeral=True)
        except Exception as exc:
            print(f"[UnifiedBot] GMRequestView.approve ERROR: {type(exc).__name__}: {exc}")
            traceback.print_exc()
            try:
                if not interaction.response.is_done():
                    await interaction.response.defer(ephemeral=True)
                await interaction.followup.send("\u274c Ошибка.", ephemeral=True)
            except Exception:
                pass

    @discord.ui.button(label="\u274c Отклонить", style=discord.ButtonStyle.danger)
    async def reject(self, interaction: discord.Interaction, button: discord.ui.Button):
        print(f"[UnifiedBot] GMRequestView.reject. user={interaction.user} request={self.request_id}")
        try:
            await interaction.response.send_modal(GMRejectReasonModal(self.bot, self.request_id))
        except Exception as exc:
            print(f"[UnifiedBot] GMRequestView.reject ERROR: {type(exc).__name__}: {exc}")
            traceback.print_exc()
            try:
                if not interaction.response.is_done():
                    await interaction.response.defer(ephemeral=True)
                await interaction.followup.send("\u274c Ошибка.", ephemeral=True)
            except Exception:
                pass


class GMRejectReasonModal(discord.ui.Modal, title="Причина отклонения"):
    reason = discord.ui.TextInput(label="Причина", style=discord.TextStyle.long, required=True, max_length=400)

    def __init__(self, bot: UnifiedBot, request_id: str):
        super().__init__()
        self.bot = bot
        self.request_id = request_id

    async def on_submit(self, interaction: discord.Interaction):
        try:
            await self.bot._resolve_gm_request(self.request_id, False, str(interaction.user), str(self.reason))
            await _safe_respond(interaction, f"\u274c Запрос отклонён.", ephemeral=True)
        except Exception as exc:
            print(f"[UnifiedBot] GMRejectReasonModal ERROR: {type(exc).__name__}: {exc}")
            traceback.print_exc()
            try:
                await _safe_respond(interaction, "\u274c Ошибка.", ephemeral=True)
            except Exception:
                pass


# ── Login Approval Views ──

class BaseAdminView(discord.ui.View):
    def __init__(self, bot: UnifiedBot, timeout: Optional[float] = 300):
        super().__init__(timeout=timeout)
        self.bot = bot

    async def interaction_check(self, interaction: discord.Interaction) -> bool:
        return await self.bot.ensure_panel_access(interaction)


class AdminRootView(BaseAdminView):
    @discord.ui.button(label="Состояние сервера", style=discord.ButtonStyle.primary)
    async def server_state(self, interaction: discord.Interaction, button: discord.ui.Button):
        await interaction.response.edit_message(embed=self.bot.build_server_state_embed(), view=ServerStateView(self.bot), content=None)

    @discord.ui.button(label="Управление игроками", style=discord.ButtonStyle.secondary)
    async def player_management(self, interaction: discord.Interaction, button: discord.ui.Button):
        await interaction.response.edit_message(embed=self.bot.build_player_management_embed(), view=PlayerManagementView(self.bot), content=None)

    @discord.ui.button(label="Команды", style=discord.ButtonStyle.success)
    async def commands_section(self, interaction: discord.Interaction, button: discord.ui.Button):
        await interaction.response.edit_message(embed=self.bot.build_commands_embed(), view=AdminCommandsView(self.bot), content=None)


class ServerStateView(BaseAdminView):
    @discord.ui.button(label="Игроки (все хабы)", style=discord.ButtonStyle.primary)
    async def players(self, interaction: discord.Interaction, button: discord.ui.Button):
        await interaction.response.defer(ephemeral=True, thinking=True)
        output = await self.bot.execute_existing_command(interaction.user, "gethubs", "")
        await send_text_result(interaction, output, ephemeral=True)

    @discord.ui.button(label="Хабы", style=discord.ButtonStyle.primary)
    async def hubs(self, interaction: discord.Interaction, button: discord.ui.Button):
        await interaction.response.defer(ephemeral=True, thinking=True)
        output = await self.bot.execute_existing_command(interaction.user, "hub", "")
        await send_text_result(interaction, output, ephemeral=True)

    @discord.ui.button(label="Логи", style=discord.ButtonStyle.secondary)
    async def logs(self, interaction: discord.Interaction, button: discord.ui.Button):
        await self.bot.open_live_logs(interaction)

    @discord.ui.button(label="Назад", style=discord.ButtonStyle.danger)
    async def back(self, interaction: discord.Interaction, button: discord.ui.Button):
        await interaction.response.edit_message(embed=self.bot.build_admin_panel_embed(), view=AdminRootView(self.bot), content=None)


class PlayerManagementView(BaseAdminView):
    @discord.ui.button(label="Бан", style=discord.ButtonStyle.danger)
    async def ban(self, interaction: discord.Interaction, button: discord.ui.Button):
        await interaction.response.send_modal(BanHDIDModal(self.bot))

    @discord.ui.button(label="Назад", style=discord.ButtonStyle.secondary)
    async def back(self, interaction: discord.Interaction, button: discord.ui.Button):
        await interaction.response.edit_message(embed=self.bot.build_admin_panel_embed(), view=AdminRootView(self.bot), content=None)


class AdminCommandsView(BaseAdminView):
    @discord.ui.button(label="ООС-сообщение", style=discord.ButtonStyle.primary)
    async def admin_ooc(self, interaction: discord.Interaction, button: discord.ui.Button):
        await interaction.response.send_modal(AdminOOCModal(self.bot))

    @discord.ui.button(label="Назад", style=discord.ButtonStyle.secondary)
    async def back(self, interaction: discord.Interaction, button: discord.ui.Button):
        await interaction.response.edit_message(embed=self.bot.build_admin_panel_embed(), view=AdminRootView(self.bot), content=None)


class LoginApprovalView(discord.ui.View):
    def __init__(self, bot: UnifiedBot, request_id: str, disabled: bool = False):
        super().__init__(timeout=None)
        self.bot = bot
        self.request_id = request_id
        if disabled:
            for child in self.children:
                child.disabled = True

    async def interaction_check(self, interaction: discord.Interaction) -> bool:
        request = self.bot.login_pending_requests.get(self.request_id)
        if request is None:
            await _safe_respond(interaction, "Эта заявка уже закрыта.", ephemeral=True)
            return False
        if not self.bot.is_admin_member(interaction.user, request.approver_id):
            await _safe_respond(interaction, "Ты не можешь обработать эту заявку.", ephemeral=True)
            return False
        return True

    @discord.ui.button(label="Подтвердить", style=discord.ButtonStyle.success)
    async def approve(self, interaction: discord.Interaction, button: discord.ui.Button):
        await self.bot.resolve_login_request(self.request_id, approved=True, resolver_name=str(interaction.user))
        await _safe_respond(interaction, "Авторизация подтверждена.", ephemeral=True)

    @discord.ui.button(label="Отклонить", style=discord.ButtonStyle.danger)
    async def reject(self, interaction: discord.Interaction, button: discord.ui.Button):
        await interaction.response.send_modal(RejectReasonModal(self.bot, self.request_id))


class RejectReasonModal(discord.ui.Modal, title="Причина отказа"):
    reason = discord.ui.TextInput(label="Причина", style=discord.TextStyle.long, required=True, max_length=400)

    def __init__(self, bot: UnifiedBot, request_id: str):
        super().__init__()
        self.bot = bot
        self.request_id = request_id

    async def on_submit(self, interaction: discord.Interaction):
        request = self.bot.login_pending_requests.get(self.request_id)
        if request is None:
            await _safe_respond(interaction, "Эта заявка уже закрыта.", ephemeral=True)
            return
        if not self.bot.is_admin_member(interaction.user, request.approver_id):
            await _safe_respond(interaction, "Ты не можешь отклонить эту заявку.", ephemeral=True)
            return
        await self.bot.resolve_login_request(
            self.request_id, approved=False,
            resolver_name=str(interaction.user),
            reason=str(self.reason),
        )
        await _safe_respond(interaction, "Заявка отклонена.", ephemeral=True)


class BanHDIDModal(discord.ui.Modal, title="Бан через /banhdid"):
    args = discord.ui.TextInput(
        label="Аргументы для /banhdid",
        placeholder='Например: 42 "Читы" "6 hours"',
        style=discord.TextStyle.long,
        required=True,
        max_length=500,
    )

    def __init__(self, bot: UnifiedBot):
        super().__init__()
        self.bot = bot

    async def on_submit(self, interaction: discord.Interaction):
        if not await self.bot.ensure_panel_access(interaction):
            return
        await interaction.response.defer(ephemeral=True, thinking=True)
        output = await self.bot.execute_existing_command(interaction.user, "banhdid", str(self.args))
        await send_text_result(interaction, output, ephemeral=True)


class AdminOOCModal(discord.ui.Modal, title="ООС-сообщение от админа"):
    nickname = discord.ui.TextInput(
        label="Ник в OOC",
        placeholder="Оставь пустым для своего ника в Discord",
        required=False,
        max_length=60,
    )
    message = discord.ui.TextInput(
        label="Сообщение",
        style=discord.TextStyle.long,
        required=True,
        max_length=500,
    )

    def __init__(self, bot: UnifiedBot):
        super().__init__()
        self.bot = bot

    async def on_submit(self, interaction: discord.Interaction):
        if not await self.bot.ensure_panel_access(interaction):
            return
        result = await self.bot.send_admin_ooc(interaction.user, str(self.nickname), str(self.message))
        await _safe_respond(interaction, result, ephemeral=True)


class LogStreamView(discord.ui.View):
    def __init__(self, bot: UnifiedBot, disabled: bool = False):
        super().__init__(timeout=None)
        self.bot = bot
        if disabled:
            for child in self.children:
                child.disabled = True

    async def interaction_check(self, interaction: discord.Interaction) -> bool:
        return await self.bot.ensure_panel_access(interaction)

    @discord.ui.button(label="Остановить", style=discord.ButtonStyle.danger)
    async def stop(self, interaction: discord.Interaction, button: discord.ui.Button):
        await self.bot.stop_log_stream(interaction.message.id)
        await _safe_respond(interaction, "Live-лог остановлен.", ephemeral=True)


# ═══════════════════════════════════════════════════════════
# MODULE-LEVEL HELPERS
# ═══════════════════════════════════════════════════════════

class WhitelistPaginator(discord.ui.View):
    def __init__(self, entries: dict, title: str, per_page: int = 15):
        super().__init__(timeout=120)
        self.entries = list(entries.items())
        self.title = title
        self.per_page = per_page
        self.page = 0
        self.total_pages = max(1, (len(self.entries) + per_page - 1) // per_page)
        self._update_buttons()

    def _format_entry(self, eid: str, info: dict) -> str:
        parts = [f"`{eid}`"]
        if info.get("name"):
            parts.append(f"\U0001f7e0 {info['name']}")
        if info.get("discord"):
            parts.append(f"\U0001f4ac {info['discord']}")
        if info.get("added_by"):
            parts.append(f"\U0001f464 {info['added_by']}")
        if info.get("added_at"):
            parts.append(f"\U0001f550 {info['added_at']}")
        return " | ".join(parts)

    def _build_embed(self):
        start = self.page * self.per_page
        end = min(start + self.per_page, len(self.entries))
        page_entries = self.entries[start:end]
        embed = discord.Embed(
            title=f"{self.title} (стр. {self.page+1}/{self.total_pages})",
            color=discord.Color.blue(),
        )
        field_parts = []
        for eid, info in page_entries:
            line = self._format_entry(eid, info)
            if not field_parts or len("\n".join(field_parts + [line])) > 950:
                if field_parts:
                    embed.add_field(name="\u200b", value="\n".join(field_parts), inline=False)
                field_parts = [line]
            else:
                field_parts.append(line)
        if field_parts:
            embed.add_field(name="\u200b", value="\n".join(field_parts), inline=False)
        embed.set_footer(text=f"{len(self.entries)} всего записей")
        return embed

    def _update_buttons(self):
        self.prev_button.disabled = self.page <= 0
        self.next_button.disabled = self.page >= self.total_pages - 1

    @discord.ui.button(label="\u25c0 Назад", style=discord.ButtonStyle.secondary)
    async def prev_button(self, interaction: discord.Interaction, button: discord.ui.Button):
        self.page -= 1
        self._update_buttons()
        await interaction.response.edit_message(embed=self._build_embed(), view=self)

    @discord.ui.button(label="Вперёд \u25b6", style=discord.ButtonStyle.secondary)
    async def next_button(self, interaction: discord.Interaction, button: discord.ui.Button):
        self.page += 1
        self._update_buttons()
        await interaction.response.edit_message(embed=self._build_embed(), view=self)


async def _safe_respond(interaction: discord.Interaction, content=None, embed=None, ephemeral: bool = False, view=None, file=None):
    kwargs = {}
    if content:
        kwargs["content"] = content
    if embed:
        kwargs["embed"] = embed
    if ephemeral:
        kwargs["ephemeral"] = ephemeral
    if view is not None:
        kwargs["view"] = view
    if file is not None:
        kwargs["file"] = file
    if interaction.response.is_done():
        await interaction.followup.send(**kwargs)
    else:
        await interaction.response.send_message(**kwargs)


async def send_text_result(interaction: discord.Interaction, text: str, ephemeral: bool = True):
    text = (text or "(пустой ответ)").strip()
    chunks = split_text(text, 1800)
    if not chunks:
        chunks = ["(пустой ответ)"]
    for index, chunk in enumerate(chunks):
        wrapped = f"```\n{chunk}\n```"
        if index == 0:
            if interaction.response.is_done():
                await interaction.followup.send(wrapped, ephemeral=ephemeral)
            else:
                await interaction.response.send_message(wrapped, ephemeral=ephemeral)
        else:
            await interaction.followup.send(wrapped, ephemeral=ephemeral)


def split_text(text: str, limit: int) -> List[str]:
    if len(text) <= limit:
        return [text]
    lines = text.splitlines() or [text]
    chunks: List[str] = []
    current = ""
    for line in lines:
        candidate = f"{current}\n{line}".strip() if current else line
        if len(candidate) > limit and current:
            chunks.append(current)
            current = line
        elif len(line) > limit:
            if current:
                chunks.append(current)
                current = ""
            for start in range(0, len(line), limit):
                chunks.append(line[start:start + limit])
        else:
            current = candidate
    if current:
        chunks.append(current)
    return chunks


def tail_file(path: str, tail_lines: int = 40, max_chars: int = 1500) -> str:
    if not path or not os.path.exists(path):
        return ""
    read_size = 65536
    with open(path, "rb") as handle:
        handle.seek(0, os.SEEK_END)
        size = handle.tell()
        handle.seek(max(0, size - read_size))
        data = handle.read()
    text = data.decode("utf-8", errors="replace")
    lines = text.splitlines()[-tail_lines:]
    tail = "\n".join(lines)
    if len(tail) > max_chars:
        tail = tail[-max_chars:]
    return tail


def _safe_int(value) -> Optional[int]:
    if value in (None, ""):
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _chunk_lines(lines, size):
    return [lines[i:i + size] for i in range(0, len(lines), size)]
