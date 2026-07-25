import asyncio
import time
import traceback
from typing import Dict, Optional

import discord
from discord import app_commands
from discord.ext import commands as discord_commands

from server import whitelist as wl_mod


class WhitelistBot(discord_commands.Bot):
    def __init__(self, server):
        intents = discord.Intents.default()
        intents.guilds = True
        intents.members = True
        intents.message_content = True
        super().__init__(command_prefix="!", intents=intents)
        self.server = server
        self.pending_requests: Dict[str, dict] = {}

    async def init(self, token: str):
        if not token:
            print("[WhitelistBot] Нет токена, бот не запущен.")
            return
        print("[WhitelistBot] Запуск Discord-бота для вайт-листа...")
        try:
            await self.start(token)
        except Exception as exc:
            print(f"[WhitelistBot] Ошибка запуска: {exc}")
            traceback.print_exc()

    async def setup_hook(self):
        guild_obj = self._guild_object()
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
            if not await self._check_admin(interaction):
                return
            etype = entry_type or "hdid"
            if wl_mod.add_entry(identifier.strip(), name or "", discord_user or "", str(interaction.user), etype):
                labels = {"hdid": "HDID", "ipid": "IPID", "ip": "IP"}
                await self._respond(interaction, f"✅ {labels.get(etype, etype.upper())} `{identifier}` добавлен в вайт-лист.")
            else:
                await self._respond(interaction, f"⚠️ Идентификатор `{identifier}` уже есть в вайт-листе.", ephemeral=True)

        @app_commands.describe(
            identifier="HDID или IPID",
            name="Новый никнейм (опционально)",
            discord_user="Новый дискорд (опционально)",
        )
        @wl.command(name="edit", description="Изменить ник/дискорд у записи")
        async def cmd_edit(interaction: discord.Interaction, identifier: str,
                           name: Optional[str] = None, discord_user: Optional[str] = None):
            if not await self._check_admin(interaction):
                return
            if wl_mod.update_entry(identifier.strip(), name or "", discord_user or ""):
                await self._respond(interaction, f"✅ `{identifier}` обновлён.")
            else:
                await self._respond(interaction, f"⚠️ `{identifier}` не найден.", ephemeral=True)

        @app_commands.describe(identifier="HDID или IPID")
        @wl.command(name="remove", description="Удалить запись из вайт-листа")
        async def cmd_remove(interaction: discord.Interaction, identifier: str):
            if not await self._check_admin(interaction):
                return
            if wl_mod.remove_entry(identifier.strip()):
                await self._respond(interaction, f"✅ `{identifier}` удалён из вайт-листа.")
            else:
                await self._respond(interaction, f"⚠️ `{identifier}` не найден.", ephemeral=True)

        @wl.command(name="list", description="Показать весь вайт-лист")
        async def cmd_list(interaction: discord.Interaction):
            if not await self._check_admin(interaction):
                return
            all_hdids = wl_mod.get_all()
            if not all_hdids:
                await self._respond(interaction, "📭 Вайт-лист пуст.")
                return
            lines = []
            for hdid, info in all_hdids.items():
                parts = [f"`{hdid}`"]
                if info.get("name"):
                    parts.append(f"🟢 {info['name']}")
                if info.get("discord"):
                    parts.append(f"💬 {info['discord']}")
                if info.get("added_by"):
                    parts.append(f"👤 {info['added_by']}")
                if info.get("added_at"):
                    parts.append(f"🕐 {info['added_at']}")
                lines.append(" | ".join(parts))
            chunks = _chunk_lines(lines, 15)
            embed = discord.Embed(
                title=f"📋 Вайт-лист ({len(all_hdids)} записей)",
                color=discord.Color.blue(),
            )
            for i, chunk in enumerate(chunks):
                embed.add_field(
                    name=f"——— стр. {i + 1} ———" if len(chunks) > 1 else "\u200b",
                    value="\n".join(chunk),
                    inline=False,
                )
            await self._respond(interaction, embed=embed)

        @wl.command(name="reload", description="Перезагрузить вайт-лист из файла")
        async def cmd_reload(interaction: discord.Interaction):
            if not await self._check_admin(interaction):
                return
            wl_mod.reload_whitelist()
            await self._respond(interaction, f"✅ Вайт-лист перезагружен. Всего: {len(wl_mod.get_all())} записей.")

        if guild_obj is not None:
            self.tree.add_command(wl, guild=guild_obj)
        self.tree.add_command(wl)

        try:
            if guild_obj is not None:
                await self.tree.sync(guild=guild_obj)
                print(f"[WhitelistBot] Команды синкнуты для гильдии {guild_obj.id}")
            await self.tree.sync()
            print("[WhitelistBot] Команды синкнуты глобально")
        except Exception as exc:
            print(f"[WhitelistBot] Ошибка синка: {exc}")
            traceback.print_exc()

    async def on_ready(self):
        print(f"[WhitelistBot] Вошёл как {self.user}.")

    # ---------- join attempt flow ----------

    async def notify_join_attempt(self, client, hdid: str, ipid: str = "", ip_address: str = ""):
        # Кулдаун: не флудим уведомлениями чаще 1 раза в 5 мин на IP/HDID
        cooldown_key = ip_address or ipid or hdid
        if not wl_mod.can_notify(cooldown_key):
            return
        cfg = self._cfg()
        channel = await self._find_channel(cfg.get("request_channel_id"), cfg.get("request_channel"))
        if channel is None:
            return
        request_id = f"wl-{int(time.time() * 1000)}-{client.id}"
        embed = discord.Embed(
            title="🔔 Попытка подключения не-вайтлистенного игрока",
            description="Игрок с неизвестным HDID/IPID/IP попытался подключиться к серверу.",
            color=discord.Color.orange(),
        )
        embed.add_field(name="HDID", value=f"`{hdid}`", inline=False)
        embed.add_field(name="IPID", value=f"`{ipid}`", inline=True)
        embed.add_field(name="IP", value=f"`{ip_address}`", inline=True)
        embed.set_footer(text="Нажмите «Добавить», чтобы внести игрока в вайт-лист.")
        view = JoinAttemptView(self, request_id, hdid)
        message = await channel.send(embed=embed, view=view)
        self.pending_requests[request_id] = {
            "hdid": hdid,
            "ipid": ipid,
            "ip": ip_address,
            "message_id": message.id,
            "channel_id": channel.id,
        }

    async def _resolve_join_attempt(self, request_id: str, hdid: str, approved: bool, resolver: str,
                                     name: str = "", discord_tag: str = ""):
        request = self.pending_requests.pop(request_id, None)
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
                        status_parts = [f"✅ Добавлен администратором {resolver}"]
                        if name:
                            status_parts.append(f"🟢 Ник: {name}")
                        if discord_tag:
                            status_parts.append(f"💬 Discord: {discord_tag}")
                        embed.add_field(name="Статус", value="\n".join(status_parts), inline=False)
                    else:
                        embed.color = discord.Color.red()
                        embed.add_field(name="❌ Статус", value=f"Отклонён администратором {resolver}", inline=False)
                    await message.edit(embed=embed, view=None)
        except Exception:
            pass

    # ---------- helpers ----------

    def _cfg(self):
        return self.server.config.get("whitelist", {})

    def _guild_object(self):
        guild_id = self._cfg().get("guild_id")
        if guild_id:
            try:
                return discord.Object(id=int(guild_id))
            except (TypeError, ValueError):
                pass
        return None

    async def _find_channel(self, channel_id, channel_name):
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

    async def _check_admin(self, interaction: discord.Interaction) -> bool:
        if not self._is_admin(interaction.user):
            await self._respond(interaction, "❌ У вас нет прав для этой команды.", ephemeral=True)
            return False
        return True

    def _is_admin(self, member) -> bool:
        cfg = self._cfg()
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

    async def _respond(self, interaction, content=None, embed=None, ephemeral=False):
        kwargs = {}
        if content:
            kwargs["content"] = content
        if embed:
            kwargs["embed"] = embed
        if ephemeral:
            kwargs["ephemeral"] = ephemeral
        try:
            if interaction.response.is_done():
                await interaction.followup.send(**kwargs)
            else:
                await interaction.response.send_message(**kwargs)
        except Exception as exc:
            print(f"[WhitelistBot] Ошибка ответа: {exc}")


def _chunk_lines(lines, size):
    return [lines[i:i + size] for i in range(0, len(lines), size)]


class JoinAttemptView(discord.ui.View):
    def __init__(self, bot: WhitelistBot, request_id: str, hdid: str):
        super().__init__(timeout=None)
        self.bot = bot
        self.request_id = request_id
        self.hdid = hdid

    async def interaction_check(self, interaction: discord.Interaction) -> bool:
        adm = self.bot._is_admin(interaction.user)
        print(f"[WhitelistBot] interaction_check: user={interaction.user} is_admin={adm}")
        if not adm:
            try:
                await interaction.response.send_message("❌ У вас нет прав.", ephemeral=True)
            except Exception as e:
                print(f"[WhitelistBot] interaction_check send ERROR: {e}")
                try:
                    await interaction.followup.send("❌ У вас нет прав.", ephemeral=True)
                except Exception:
                    pass
            return False
        return True

    async def on_error(self, interaction: discord.Interaction, error: Exception, item):
        print(f"[WhitelistBot] View on_error: {type(error).__name__}: {error}")
        traceback.print_exc()
        try:
            if not interaction.response.is_done():
                await interaction.response.defer(ephemeral=True)
            await interaction.followup.send("❌ Произошла ошибка.", ephemeral=True)
        except Exception:
            pass

    @discord.ui.button(label="✅ Добавить в вайт-лист", style=discord.ButtonStyle.success)
    async def approve(self, interaction: discord.Interaction, button: discord.ui.Button):
        print(f"[WhitelistBot] approve() called. user={interaction.user} request={self.request_id}")
        try:
            # Defer first — даём себе время на API-вызовы
            await interaction.response.defer(ephemeral=True)
            request = self.bot.pending_requests.get(self.request_id, {})
            if request.get("ip"):
                identifier, entry_type = request["ip"], "ip"
            elif request.get("ipid"):
                identifier, entry_type = request["ipid"], "ipid"
            else:
                identifier, entry_type = self.hdid, "hdid"
            print(f"[WhitelistBot] approve: adding {entry_type}={identifier}")
            added = wl_mod.add_entry(identifier, "", "", str(interaction.user), entry_type)
            if added:
                await self.bot._resolve_join_attempt(self.request_id, self.hdid, True, str(interaction.user))
                labels = {"hdid": "HDID", "ipid": "IPID", "ip": "IP"}
                await interaction.followup.send(f"✅ `{identifier}` ({labels[entry_type]}) добавлен.", ephemeral=True)
            else:
                await interaction.followup.send(f"⚠️ `{identifier}` уже есть в вайт-листе.", ephemeral=True)
        except Exception as exc:
            print(f"[WhitelistBot] approve ERROR: {type(exc).__name__}: {exc}")
            traceback.print_exc()
            try:
                if not interaction.response.is_done():
                    await interaction.response.defer(ephemeral=True)
                await interaction.followup.send("❌ Ошибка.", ephemeral=True)
            except Exception:
                pass

    @discord.ui.button(label="❌ Отклонить", style=discord.ButtonStyle.danger)
    async def reject(self, interaction: discord.Interaction, button: discord.ui.Button):
        print(f"[WhitelistBot] reject() called. user={interaction.user} request={self.request_id}")
        try:
            await interaction.response.defer(ephemeral=True)
            await self.bot._resolve_join_attempt(self.request_id, self.hdid, False, str(interaction.user))
            await interaction.followup.send(f"❌ Запрос на `{self.hdid}` отклонён.", ephemeral=True)
        except Exception as exc:
            print(f"[WhitelistBot] reject ERROR: {type(exc).__name__}: {exc}")
            traceback.print_exc()
            try:
                if not interaction.response.is_done():
                    await interaction.response.defer(ephemeral=True)
                await interaction.followup.send("❌ Ошибка.", ephemeral=True)
            except Exception:
                pass
