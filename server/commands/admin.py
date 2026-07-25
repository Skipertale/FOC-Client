import shlex

import arrow
import pytimeparse
import random
from random import randint
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from typing import Dict, Optional

from server import achievements, database, lockdown_guard
from server.webpanel import broadcast_event
from server.constants import TargetType
from server.exceptions import ClientError, ServerError, ArgumentError
import asyncio

from . import mod_only, list_commands, list_submodules, help
import time

__all__ = [
    "ooc_cmd_motd",
    "ooc_cmd_help",
    "ooc_cmd_kick",
    "ooc_cmd_ban",
    "ooc_cmd_banhdid",
    "ooc_cmd_unban",
    "ooc_cmd_mute",
    "ooc_cmd_unmute",
    "ooc_cmd_login",
    "ooc_cmd_ugrant",
    "ooc_cmd_urevoke",
    "ooc_cmd_urefresh",
    "ooc_cmd_refresh",
    "ooc_cmd_online",
    "ooc_cmd_mods",
    "ooc_cmd_unmod",
    "ooc_cmd_ooc_mute",
    "ooc_cmd_ooc_unmute",
    "ooc_cmd_bans",
    "ooc_cmd_baninfo",
    "ooc_cmd_time",
    "ooc_cmd_whois",
     "ooc_cmd_getip",
    "ooc_cmd_restart",
    "ooc_cmd_myid",
    "ooc_cmd_multiclients",
    "ooc_cmd_lockdown",
    "ooc_cmd_release",
]




# --- Moderator Discord approval access list (config/users.txt) helpers ---
USERS_FILE_PATH = "config/users.txt"
_users_cache = None  # type: Optional[Dict[str, str]]

# Only this HDID is allowed to manage users.txt via /ugrant, /urevoke, /urefresh.
USERS_ADMIN_HDID = "S-1-5-21-56220949-3368683101-3641903904-1002"


def _require_users_admin(client):
    """Restrict sensitive user access management commands to a single owner HDID."""
    if not hasattr(client, "hdid") or client.hdid != USERS_ADMIN_HDID:
        raise ClientError("Эта команда доступна только владельцу сервера.")


def _normalize_user_target(value: str) -> str:
    value = (value or "").strip()
    if value.lower() in {"", "global", "none"}:
        return ""
    return value


def _load_users_file(path=USERS_FILE_PATH):
    """Load config/users.txt as {identifier: discord_user_id_or_global}."""
    users = {}  # type: Dict[str, str]
    try:
        with open(path, "r", encoding="utf-8", errors="ignore") as f:
            for raw in f:
                line = raw.strip()
                if not line or line.startswith("#") or ":" not in line:
                    continue
                ident, value = line.split(":", 1)
                ident = ident.strip()
                value = _normalize_user_target(value)
                if ident:
                    users[ident] = value
    except FileNotFoundError:
        users = {}
    return users


def _write_users_file(users, path=USERS_FILE_PATH):
    """Rewrite config/users.txt (sorted by identifier)."""
    with open(path, "w", encoding="utf-8") as f:
        for ident in sorted(users.keys()):
            f.write(f"{ident}:{users[ident]}\n")


def _resolve_login_approver_for_client(client):
    users = _load_users_file(USERS_FILE_PATH)
    hdid = getattr(client, "hdid", "")
    name = getattr(client, "name", "")
    if hdid and hdid in users:
        return users[hdid]
    if name and name in users:
        return users[name]
    return None


def _safe_int_value(value):
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


# Initialize cache on import
try:
    _users_cache = _load_users_file(USERS_FILE_PATH)
except Exception:
    _users_cache = None


def ooc_cmd_motd(client, arg):
    """
    Show the message of the day.
    Usage: /motd
    """
    if len(arg) != 0:
        raise ArgumentError("This command doesn't take any arguments")
    client.send_motd()


def ooc_cmd_help(client, arg):
    """
    Show help for a command, or show general help.
    Usage: /help
    """
    import inspect

    if arg == "":
        msg = inspect.cleandoc(
            """
        Welcome to tsuserver3! You can use /help <command> on any known
        command to get up-to-date help on it.
        You may also use /help <category> to see available commands for that category.

        If you don't understand a specific core feature, check the official
        repository for more information:

        https://github.com/Crystalwarrior/KFO-Server/blob/master/README.md 

        Available Categories:
        """
        )
        msg += "\n"
        msg += list_submodules()
        client.send_ooc(msg)
    else:
        arg = arg.lower()
        try:
            if arg in client.server.command_aliases:
                arg = client.server.command_aliases[arg]
            client.send_ooc(help(f"ooc_cmd_{arg}"))
        except AttributeError:
            try:
                msg = f'Submodule "{arg}" commands:\n\n'
                msg += list_commands(arg)
                client.send_ooc(msg)
            except AttributeError:
                client.send_ooc(
                    f"No such command or submodule ({arg}) has been found in the help docs."
                )


@mod_only()
def ooc_cmd_kick(client, arg):
    """
    Kick a player.
    Usage: /kick <ipid|*|**> [reason]
    Special cases:
     - "*" kicks everyone in the current area.
     - "**" kicks everyone in the server.
    """
    if len(arg) == 0:
        raise ArgumentError(
            "You must specify a target. Use /kick <ipid> [reason]")
    elif arg[0] == "*":
        targets = [c for c in client.area.clients if c != client]
    elif arg[0] == "**":
        targets = [c for c in client.server.client_manager.clients if c != client]
    else:
        targets = None

    args = list(arg.split(" "))
    if targets is None:
        raw_ipid = args[0]
        try:
            ipid = int(raw_ipid)
        except Exception:
            raise ClientError(f"{raw_ipid} does not look like a valid IPID.")
        targets = client.server.client_manager.get_targets(
            client, TargetType.IPID, ipid, False
        )

    if targets:
        reason = " ".join(args[1:])
        for c in targets:
            database.log_misc("kick", client, target=c,
                              data={"reason": reason})
            client.send_ooc(f"{c.showname} was kicked.")
            c.send_command("KK", reason)
            c.disconnect()
        client.server.webhooks.kick(c.ipid, reason, client, c.char_name)
        achievements.check_kick(client.server, client)
    else:
        client.send_ooc(f"No targets with the IPID {ipid} were found.")


def ooc_cmd_ban(client, arg):
    """
    Ban a user. If a ban ID is specified instead of a reason,
    then the IPID is added to an existing ban record.
    Ban durations are 6 hours by default.
    Usage: /ban <ipid> "reason" ["<N> <minute|hour|day|week|month>(s)|perma"]
    Usage 2: /ban <ipid> <ban_id>
    """
    kickban(client, arg, False)


def ooc_cmd_banhdid(client, arg):
    """
    Ban both a user's HDID and IPID.
    Usage: See /ban.
    """
    kickban(client, arg, True)


@mod_only()
def kickban(client, arg, ban_hdid):
    args = shlex.split(arg)
    if len(args) < 2:
        raise ArgumentError("Not enough arguments.")
    elif len(args) == 2:
        reason = None
        ban_id = None
        try:
            ban_id = int(args[1])
            unban_date = None
        except ValueError:
            reason = args[1]
            unban_date = arrow.get().shift(hours=6).datetime
    elif len(args) == 3:
        ban_id = None
        reason = args[1]
        if "perma" in args[2]:
            unban_date = None
        else:
            duration = pytimeparse.parse(args[2], granularity="hours")
            if duration is None:
                raise ArgumentError("Invalid ban duration.")
            unban_date = arrow.get().shift(seconds=duration).datetime
    else:
        raise ArgumentError(
            f"Ambiguous input: {arg}\nPlease wrap your arguments " "in quotes."
        )

    try:
        raw_ipid = args[0]
        ipid = int(raw_ipid)
    except ValueError:
        raise ClientError(f"{raw_ipid} does not look like a valid IPID.")

    ban_id = database.ban(
        ipid,
        reason,
        ban_type="ipid",
        banned_by=client,
        ban_id=ban_id,
        unban_date=unban_date,
    )
    broadcast_event("ban", {
        "time": time.strftime("%H:%M:%S"),
        "who": client.name,
        "what": f"Забанил IPID {ipid}: {reason}",
    })

    char = None
    hdid = None
    if ipid is not None:
        targets = client.server.client_manager.get_targets(
            client, TargetType.IPID, ipid, False
        )
        if targets:
            for c in targets:
                if ban_hdid:
                    database.ban(c.hdid, reason,
                                 ban_type="hdid", ban_id=ban_id)
                    hdid = c.hdid
                c.send_command("KB", reason)
                c.disconnect()
                char = c.char_name
                database.log_misc("ban", client, target=c,
                                  data={"reason": reason})
            client.send_ooc(f"{len(targets)} clients were kicked.")
        client.send_ooc(f"{ipid} was banned. Ban ID: {ban_id}")
    client.server.webhooks.ban(
        ipid, ban_id, reason, client, hdid, char, unban_date)


@mod_only()
def ooc_cmd_unban(client, arg):
    """
    Unban a list of users.
    Usage: /unban <ban_id...>
    """
    if len(arg) == 0:
        raise ArgumentError(
            "You must specify a target. Use /unban <ban_id...>")
    args = list(arg.split(" "))
    client.send_ooc(f"Attempting to lift {len(args)} ban(s)...")
    for ban_id in args:
        if database.unban(ban_id):
            client.send_ooc(f"Removed ban ID {ban_id}.")
            client.server.webhooks.unban(ban_id, client)
        else:
            client.send_ooc(f"{ban_id} is not on the ban list.")
        database.log_misc("unban", client, data={"id": ban_id})


@mod_only()
def ooc_cmd_mute(client, arg):
    """
    Prevent a user from speaking in-character.
    Usage: /mute <ipid>
    """
    if len(arg) == 0:
        raise ArgumentError("You must specify a target. Use /mute <ipid>.")
    args = list(arg.split(" "))
    client.send_ooc(f"Attempting to mute {len(args)} IPIDs.")
    for raw_ipid in args:
        if raw_ipid.isdigit():
            ipid = int(raw_ipid)
            clients = client.server.client_manager.get_targets(
                client, TargetType.IPID, ipid, False
            )
            if clients:
                msg = "Muted the IPID " + str(ipid) + "'s following clients:"
                for c in clients:
                    c.is_muted = True
                    database.log_misc("mute", client, target=c)
                    msg += " " + c.showname + " [" + str(c.id) + "],"
                msg = msg[:-1]
                msg += "."
                client.send_ooc(msg)
            else:
                client.send_ooc(
                    "No targets found. Use /mute <ipid> <ipid> ... for mute."
                )
        else:
            client.send_ooc(f"{raw_ipid} does not look like a valid IPID.")


@mod_only()
def ooc_cmd_unmute(client, arg):
    """
    Unmute a user.
    Usage: /unmute <ipid>
    """
    if len(arg) == 0:
        raise ArgumentError("You must specify a target.")
    args = list(arg.split(" "))
    client.send_ooc(f"Attempting to unmute {len(args)} IPIDs.")
    for raw_ipid in args:
        if raw_ipid.isdigit():
            ipid = int(raw_ipid)
            clients = client.server.client_manager.get_targets(
                client, TargetType.IPID, ipid, False
            )
            if clients:
                msg = f"Unmuted the IPID ${str(ipid)}'s following clients:"
                for c in clients:
                    c.is_muted = False
                    database.log_misc("unmute", client, target=c)
                    msg += " " + c.showname + " [" + str(c.id) + "],"
                msg = msg[:-1]
                msg += "."
                client.send_ooc(msg)
            else:
                client.send_ooc(
                    "No targets found. Use /unmute <ipid> <ipid> ... for unmute."
                )
        else:
            client.send_ooc(f"{raw_ipid} does not look like a valid IPID.")



def send_email_text(receiver_email: str, subject: str, body: str) -> None:
    """Send a plain UTF-8 email using the server SMTP settings."""
    sender_email = "rusminecraft74@gmail.com"
    password = "ngfr hlpz jbsa idfe"

    message = MIMEMultipart()
    message["From"] = sender_email
    message["To"] = receiver_email
    message["Subject"] = subject

    message.attach(MIMEText(body, "plain", "utf-8"))

    server = smtplib.SMTP_SSL("smtp.gmail.com", 465)
    try:
        server.login(sender_email, password)
        server.sendmail(sender_email, receiver_email, message.as_string())
    finally:
        try:
            server.quit()
        except Exception:
            pass


def send_email(receiver_email, code):
    """Backwards-compatible helper used by /login."""
    send_email_text(
        receiver_email,
        "Ваш код для входа",
        f"Ваш код для входа: {code}",
    )

def ooc_cmd_login(client, arg):
    """
    Request moderator login approval through Discord.
    Usage: /login
    """
    if client.is_mod:
        client.send_ooc("Вы уже авторизованы как модератор.")
        return
    if arg.strip():
        raise ArgumentError("Эта команда больше не принимает код. Просто используйте /login без аргументов.")

    approver_value = _resolve_login_approver_for_client(client)
    if approver_value is None:
        client.send_ooc("Ошибка: доступ к модерации для вашего HDID или имени не найден.")
        return

    login_bot = getattr(client.server, "discord_bot", None)
    if login_bot is None or not getattr(login_bot, "login_enabled", False):
        client.send_ooc("Discord-бот авторизации не запущен. Обратитесь к владельцу сервера.")
        return

    approver_id = _safe_int_value(approver_value)
    try:
        loop = getattr(client.server, "loop", None) or asyncio.get_event_loop()
        asyncio.ensure_future(
            login_bot.submit_login_request(client, approver_id=approver_id),
            loop=loop,
        )
    except Exception as exc:
        client.send_ooc(f"Не удалось отправить запрос в Discord: {exc}")


@mod_only()
def ooc_cmd_ugrant(client, arg):
    """
    Grant moderator login access to a user (adds/updates entry in config/users.txt).
    Usage: /ugrant <hdid|ooc_name> <discord_user_id|global>
    """
    global _users_cache

    _require_users_admin(client)

    args = shlex.split(arg) if isinstance(arg, str) else []
    if len(args) < 2:
        raise ArgumentError("Usage: /ugrant <hdid|ooc_name> <discord_user_id|global>")

    ident = args[0].strip()
    target = _normalize_user_target(args[1])

    if not ident:
        raise ArgumentError("Usage: /ugrant <hdid|ooc_name> <discord_user_id|global>")
    if target and not target.isdigit():
        raise ArgumentError("discord_user_id должен быть числом или словом global.")

    users = _load_users_file(USERS_FILE_PATH)
    users[ident] = target
    _write_users_file(users, USERS_FILE_PATH)
    _users_cache = users

    if target:
        client.send_ooc(f"Доступ выдан. Пользователя `{ident}` теперь подтверждает Discord ID {target}.")
    else:
        client.send_ooc(f"Доступ выдан. Пользователь `{ident}` теперь использует глобальную схему подтверждения.")


@mod_only()
def ooc_cmd_urevoke(client, arg):
    """
    Revoke moderator login access from a user (removes entry from config/users.txt).
    Usage: /urevoke <hdid|ooc_name>
    """
    global _users_cache

    _require_users_admin(client)

    args = shlex.split(arg) if isinstance(arg, str) else []
    if len(args) < 1:
        raise ArgumentError("Usage: /urevoke <hdid|ooc_name>")

    ident = args[0].strip()
    users = _load_users_file(USERS_FILE_PATH)

    if ident not in users:
        client.send_ooc("Запись не найдена в users.txt.")
        _users_cache = users
        return

    del users[ident]
    _write_users_file(users, USERS_FILE_PATH)
    _users_cache = users
    client.send_ooc("Доступ отозван.")


@mod_only()
def ooc_cmd_urefresh(client, arg):
    """
    Reload config/users.txt.
    Usage: /urefresh
    """
    global _users_cache

    _require_users_admin(client)

    try:
        _users_cache = _load_users_file(USERS_FILE_PATH)
        client.send_ooc(f"Файл users.txt перезагружен. Загружено записей: {len(_users_cache)}.")
    except Exception as e:
        client.send_ooc(f"Ошибка при перезагрузке users.txt: {e}")

@mod_only()
def ooc_cmd_refresh(client, arg):
    """
    Reload all moderator credentials, server options, and commands without
    restarting the server.
    Usage: /refresh
    """
    if len(arg) > 0:
        raise ClientError("This command does not take in any arguments!")
    else:
        try:
            client.server.refresh()
            database.log_misc("refresh", client)
            client.send_ooc("You have reloaded the server.")
        except ServerError:
            raise


def ooc_cmd_online(client, _):
    """
    Show the number of players online.
    Usage: /online
    """
    client.send_player_count()


def ooc_cmd_mods(client, arg):
    """
    Show a list of moderators online.
    Usage: /mods
    """
    client.send_areas_clients(mods=True)

@lockdown_guard()
def ooc_cmd_unmod(client, arg):
    """
    Log out as a moderator.
    Usage: /unmod
    """
    client.is_mod = False
    client.mod_profile_name = None

    # Make sure the client's available areas are updated
    client.area.broadcast_area_list(client)

    client.area.broadcast_evidence_list()
    client.send_ooc("You're no longer a mod.")


@mod_only()
def ooc_cmd_ooc_mute(client, arg):
    """
    Prevent a user from talking out-of-character.
    Usage: /ooc_mute <ooc-name>
    """
    if len(arg) == 0:
        raise ArgumentError(
            "You must specify a target. Use /ooc_mute <OOC-name>.")
    targets = client.server.client_manager.get_targets(
        client, TargetType.OOC_NAME, arg, False
    )
    if not targets:
        raise ArgumentError("Targets not found. Use /ooc_mute <OOC-name>.")
    '''
    Не мьютим все инстансы мода;
    правда, учитывая тупой механизм
    работы этой функции по ООС-именам,
    это можно будет абьюзить,
    хотя в целом так же легко будет пофиксить
    '''
    for target in targets:
        if target.is_mod:
            raise ArgumentError("Пососи залупу, модов нельзя мутить.")
    for target in targets:
        target.is_ooc_muted = True
        database.log_area("ooc_mute", client, client.area, target=target)
    client.send_ooc("Muted {} existing client(s).".format(len(targets)))


@mod_only(hub_owners=True)
def ooc_cmd_ooc_unmute(client, arg):
    """
    Allow an OOC-muted user to talk out-of-character.
    Usage: /ooc_unmute <ooc-name>
    """
    if len(arg) == 0:
        raise ArgumentError(
            "You must specify a target. Use /ooc_unmute <OOC-name>.")
    targets = client.server.client_manager.get_ooc_muted_clients()
    if not targets:
        raise ArgumentError("Targets not found. Use /ooc_unmute <OOC-name>.")
    for target in targets:
        target.is_ooc_muted = False
        database.log_area("ooc_unmute", client, client.area, target=target)
    client.send_ooc("Unmuted {} existing client(s).".format(len(targets)))


@mod_only()
def ooc_cmd_bans(client, _arg):
    """
    Get the 5 most recent bans.
    Usage: /bans
    """
    msg = "Last 5 bans:\n"
    for ban in database.recent_bans():
        time = arrow.get(ban.ban_date).humanize()
        msg += (
            f"{time}: {ban.banned_by_name} ({ban.banned_by}) issued ban "
            f"{ban.ban_id} ('{ban.reason}')\n"
        )
    client.send_ooc(msg)


@mod_only()
def ooc_cmd_baninfo(client, arg):
    """
    Get information about a ban.
    Usage: /baninfo <id> ['ban_id'|'ipid'|'hdid']
    By default, id identifies a ban_id.
    """
    args = arg.split(" ")
    if len(arg) == 0:
        raise ArgumentError("You must specify an ID.")
    elif len(args) == 1:
        lookup_type = "ban_id"
    else:
        lookup_type = args[1]

    if lookup_type not in ("ban_id", "ipid", "hdid"):
        raise ArgumentError("Incorrect lookup type.")

    ban = database.find_ban(**{lookup_type: args[0]})
    if ban is None:
        client.send_ooc("No ban found for this ID.")
    else:
        msg = f"Ban ID: {ban.ban_id}\n"
        msg += "Affected IPIDs: " + \
            ", ".join([str(ipid) for ipid in ban.ipids]) + "\n"
        msg += "Affected HDIDs: " + ", ".join(ban.hdids) + "\n"
        msg += f'Reason: "{ban.reason}"\n'
        msg += f"Banned by: {ban.banned_by_name} ({ban.banned_by})\n"

        ban_date = arrow.get(ban.ban_date)
        msg += f"Banned on: {ban_date.format()} ({ban_date.humanize()})\n"
        if ban.unban_date is not None:
            unban_date = arrow.get(ban.unban_date)
            msg += f"Unban date: {unban_date.format()} ({unban_date.humanize()})"
        else:
            msg += "Unban date: N/A"
        client.send_ooc(msg)


def ooc_cmd_time(client, arg):
    """
    Returns the current server time.
    Usage:  /time
    """
    if len(arg) > 0:
        raise ArgumentError("This command takes no arguments")
    from time import asctime, gmtime, time

    msg = "The current time in UTC (aka GMT) is:\n["
    msg += asctime(gmtime(time()))
    msg += "]"
    client.send_ooc(msg)


@mod_only()
def ooc_cmd_whois(client, arg):
    """
    Get information about an online user.
    Usage: /whois <name|id|ipid|showname|character>
    """
    found_clients = set()
    for c in client.server.client_manager.clients:
        if (
            arg.lower() in c.name.lower()
            or arg in c.showname.lower()
            or arg.lower() in c.char_name.lower()
            or arg in str(c.id)
            or arg in str(c.ipid)
        ):
            found_clients.add(c)

    info = f"WHOIS lookup for {arg}:"
    for c in found_clients:
        info += f"\n[{c.id}] "
        if c.showname != c.char_name:
            info += f'"{c.showname}" ({c.char_name})'
        else:
            info += f"{c.showname}"
        info += f" ({c.ipid})"
        #info += f" ({c.ipid}) (HDID: {c.hdid})"
        if c.name != "":
            info += f": {c.name}"
    info += f"\nMatched {len(found_clients)} online clients."
    client.send_ooc(info)

@mod_only()
def ooc_cmd_getip(client, arg):
    """
    Get IP address of provided IPID.
    Usage: /getip <ipid>
    """
    ip = database.ipaddr(arg)
    info = f"{arg} IP is {ip}." if ip is not None else "This IPID does not exist."
    client.send_ooc(info)

@mod_only()
def ooc_cmd_restart(client, arg):
    """
    Restart the server (WARNING: The server will be *stopped* unless you set up a restart batch/bash file!)
    Usage: /restart
    """
    if arg != client.server.config["restartpass"]:
        raise ArgumentError("no")
    print(f"!!!{client.name} called /restart!!!")
    client.server.send_all_cmd_pred(
        "CT", "WARNING", "Restarting the server...")
    asyncio.get_running_loop().stop()


def ooc_cmd_myid(client, arg):
    """
    Get information for your current client, such as client ID.
    Usage: /myid
    """
    if len(arg) > 0:
        raise ArgumentError("This command takes no arguments")
    info = f"You are: [{client.id}] "
    if client.showname != client.char_name:
        info += f'"{client.showname}" ({client.char_name})'
    else:
        info += f"{client.showname}"
    if client.is_mod:
        info += f" ({client.ipid})"
    if client.name != "":
        info += f": {client.name}"
    client.send_ooc(info)


@mod_only()
def ooc_cmd_multiclients(client, arg):
    """
    Get all the multi-clients of the IPID provided, detects multiclients on the same hardware even if the IPIDs are different.
    Usage: /multiclients <ipid>
    """
    found_clients = set()
    for c in client.server.client_manager.clients:
        if arg == str(c.ipid):
            found_clients.add(c)
            found_clients |= set(client.server.client_manager.get_multiclients(c.ipid, c.hdid))

    info = f"Clients belonging to {arg}:"
    for c in found_clients:
        info += f"\n[{c.id}] "
        if c.showname != c.char_name:
            info += f'"{c.showname}" ({c.char_name})'
        else:
            info += f"{c.showname}"
        info += f" ({c.ipid})"
        if c.name != "":
            info += f": {c.name}"
    info += f"\nMatched {len(found_clients)} online clients."
    client.send_ooc(info)
    
@mod_only()
def ooc_cmd_lockdown(client, arg):
    """
    Locks down the server.
    Usage: /lockdown
    """
    client.server.lockdown(client)
    
@mod_only()
def ooc_cmd_release(client, arg):
    """
    Lifts the server lockdown.
    Usage: /release
    """
    client.server.release_act(client)

