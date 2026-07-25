import asyncio
import csv
import io
import json
import math
import logging
import sys
import time
from pathlib import Path
from datetime import datetime, timedelta

from fastapi import FastAPI, Request, WebSocket, WebSocketDisconnect
from fastapi.responses import HTMLResponse, RedirectResponse, JSONResponse, FileResponse, StreamingResponse
from jinja2 import Environment, FileSystemLoader

from server import database as db

logger = logging.getLogger("webpanel")

HERE = Path(__file__).resolve().parent
_crm_db_path = None
templates = Environment(loader=FileSystemLoader(str(HERE)))

# Import CRM repository
sys.path.insert(0, str(HERE.parent.parent))
try:
    from admin_crm.app import CRMRepository
    _crm_repo = None
except ImportError as e:
    logger.warning("CRM import failed: %s", e)
    CRMRepository = None
    _crm_repo = None

_ws_clients = set()
_server_ref = None
_config = {}
_webpanel_running = False
_player_code_sessions = {}  # token -> {"code": str, "created_at": float}


def broadcast_event(event_type, data):
    if not _webpanel_running:
        return
    msg = json.dumps({"type": event_type, **data})
    for ws in list(_ws_clients):
        try:
            asyncio.ensure_future(ws.send_text(msg))
        except Exception:
            _ws_clients.discard(ws)


def render(name, request=None, **kwargs):
    tpl = templates.get_template(name)
    ctx = {"cfg": _config}
    if request:
        acc = _account(request)
        if acc:
            ctx["acc"] = acc
            ctx["role"] = str(acc.get("role", "user"))
        ctx["request"] = request
    ctx.update(kwargs)
    return HTMLResponse(tpl.render(**ctx), media_type="text/html; charset=utf-8")


def _auth(request: Request):
    if not _crm_repo:
        return None
    token = request.cookies.get("wp_session") or request.cookies.get("crm_session")
    if token:
        try:
            acc = _crm_repo.get_account_by_session(token)
            if acc and _crm_repo.has_panel_access(acc):
                return True
        except Exception:
            pass
    return None


def _account(request: Request):
    token = request.cookies.get("wp_session") or request.cookies.get("crm_session")
    if token and _crm_repo:
        try:
            acc = _crm_repo.get_account_by_session(token)
            if acc:
                return dict(acc)
        except Exception:
            pass
    return None


def _account_from_ws(ws: WebSocket):
    token = ws.cookies.get("wp_session") or ws.cookies.get("crm_session")
    if token and _crm_repo:
        try:
            acc = _crm_repo.get_account_by_session(token)
            if acc:
                return dict(acc)
        except Exception:
            pass
    return None


ROLE_HIERARCHY = {"superadmin": 100, "admin": 80, "moderator": 60, "ga": 40, "gm": 30, "user": 20}

def _has_role(acc, *roles):
    if not acc:
        return False
    return str(acc.get("role")) in roles or str(acc.get("role")) == "superadmin"

def _role_at_least(acc, min_role):
    if not acc:
        return False
    return ROLE_HIERARCHY.get(str(acc.get("role")), 0) >= ROLE_HIERARCHY.get(min_role, 0)

def _player_code_session(request):
    token = request.cookies.get("player_code_session")
    if token and token in _player_code_sessions:
        return _player_code_sessions[token]
    return None

def _player_code_session_from_ws(ws):
    token = ws.cookies.get("player_code_session")
    if token and token in _player_code_sessions:
        return _player_code_sessions[token]
    return None

def _cli(ipid):
    srv = _server_ref
    if not srv:
        return None
    for c in srv.client_manager.clients:
        if c.ipid == ipid:
            return c
    return None


def _log_admin(action, data=None):
    try:
        from server import database as db
        db.log_misc("admin_action", data={"action": action, **(data or {})})
    except Exception:
        pass


def make_app(server, cfg):
    global _server_ref, _config, _webpanel_running, _crm_db_path, _crm_repo
    _server_ref = server
    _config = cfg
    _webpanel_running = True
    _crm_db_path = cfg.get("crm_db") or None
    if CRMRepository and not _crm_repo:
        try:
            _crm_repo = CRMRepository(
                source_db=HERE.parent.parent / "storage" / "db.sqlite3",
                crm_db=Path(_crm_db_path) if _crm_db_path else (HERE.parent.parent / "admin_crm" / "crm.sqlite3"),
            )
            _crm_repo.maybe_start_auto_sync()
            logger.info("CRM repository initialized")
        except Exception as e:
            logger.warning("CRM init failed: %s", e)

    import sqlite3 as _sqlite3
    _wp_db_conn = _sqlite3.connect("storage/db.sqlite3", check_same_thread=False)
    _wp_db_conn.execute("PRAGMA journal_mode=WAL")
    _wp_db_conn.execute("PRAGMA busy_timeout=5000")
    _wp_db_conn.row_factory = _sqlite3.Row

    app = FastAPI(title="KFO-Server Web Panel")

    @app.middleware("http")
    async def _role_access_mw(request: Request, call_next):
        path = request.url.path
        acc = _account(request)
        if acc:
            role = str(acc.get("role"))
            if role == "gm":
                if path not in ("/player", "/ws/player", "/player.js", "/style.css", "/app.js", "/login", "/crm.js"):
                    return RedirectResponse(url="/player")
            elif role == "user":
                allowed = ("/", "/login", "/style.css", "/app.js", "/api/verify_player_code", "/player", "/player_login", "/ws/player", "/player.js", "/player.html")
                if path not in allowed:
                    return RedirectResponse(url="/")
        return await call_next(request)

    @app.exception_handler(Exception)
    async def _global_exc_handler(request, exc):
        logger.error("Unhandled in %s: %s", request.url.path, exc)
        return JSONResponse({"ok": False, "error": str(exc)}, status_code=500)

    @app.get("/style.css")
    async def css():
        return FileResponse(str(HERE / "style.css"))

    @app.get("/app.js")
    async def js():
        return FileResponse(str(HERE / "app.js"))

    @app.get("/player.js")
    async def player_js():
        return FileResponse(str(HERE / "player.js"))

    @app.get("/player")
    async def player_page(request: Request):
        # GM+ can access directly
        if _auth(request):
            acc = _account(request)
            if _role_at_least(acc, "gm"):
                return render("player.html", request=request)
        # Player code access
        if _player_code_session(request):
            return render("player.html", request=request)
        # Show code input
        return render("player_login.html", request=request)

    @app.get("/")
    async def index(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if acc and str(acc.get("role")) == "gm":
            return RedirectResponse(url="/player")
        return render("dashboard.html", request=request)

    @app.get("/login")
    async def login_page(request: Request):
        if _auth(request):
            return RedirectResponse(url="/")
        return render("login.html")

    @app.post("/login")
    async def login_post(request: Request):
        if not _crm_repo:
            return JSONResponse({"ok": False, "error": "CRM не загружен"}, status_code=503)
        body = await request.json()
        username = body.get("username") or ""
        password = body.get("password") or ""
        if not username or not password:
            return JSONResponse({"ok": False, "error": "Введите логин и пароль"}, status_code=400)
        try:
            acc = _crm_repo.authenticate_account(username, password)
            if not acc:
                return JSONResponse({"ok": False, "error": "Неверный логин или пароль"}, status_code=403)
            ua = request.headers.get("user-agent", "")
            ip = request.client.host if request.client else ""
            token = _crm_repo.create_account_session(int(acc["id"]), ua, ip)
            resp = JSONResponse({"ok": True, "token": token,
                                 "account": {"id": acc["id"], "username": acc["username"], "display_name": acc["display_name"] if acc["display_name"] else "", "role": acc["role"], "can_access_panel": acc["can_access_panel"]}})
            resp.set_cookie("crm_session", token, httponly=True, max_age=86400, samesite="lax")
            resp.set_cookie("wp_session", token, httponly=True, max_age=86400, samesite="lax")
            return resp
        except Exception as exc:
            logger.error("Login error: %s", exc)
            return JSONResponse({"ok": False, "error": "Ошибка сервера"}, status_code=500)

    @app.get("/players")
    async def players_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        return render("players.html", request=request)

    @app.get("/bans")
    async def bans_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        return render("bans.html", request=request)

    @app.get("/logs")
    async def logs_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        return render("logs.html", request=request)

    @app.get("/rooms")
    async def rooms_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        return render("rooms.html", request=request)

    @app.get("/crm.js")
    async def crm_js():
        return FileResponse(str(HERE / "crm.js"))

    @app.get("/crm")
    async def crm_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if not _role_at_least(acc, "ga"):
            return render("login.html", request=request, error="Доступ запрещён")
        return render("crm.html", request=request)

    # ─── Account API ────────────────────────────────────────────────

    @app.post("/api/register")
    async def api_register(request: Request):
        if not _crm_repo:
            return JSONResponse({"ok": False, "error": "CRM не загружен"}, status_code=503)
        body = await request.json()
        username = (body.get("username") or "").strip()
        password = body.get("password") or ""
        display_name = (body.get("display_name") or username).strip()
        try:
            _crm_repo.register_account(username, password, display_name)
            return {"ok": True}
        except Exception as exc:
            msg = str(exc)
            if "UNIQUE" in msg:
                msg = "Такой логин уже существует"
            return JSONResponse({"ok": False, "error": msg}, status_code=400)

    @app.post("/api/account/login")
    async def api_account_login(request: Request):
        if not _crm_repo:
            return JSONResponse({"ok": False, "error": "CRM не загружен"}, status_code=503)
        body = await request.json()
        try:
            acc = _crm_repo.authenticate_account(body.get("username", ""), body.get("password", ""))
            if not acc:
                return JSONResponse({"ok": False, "error": "Неверный логин или пароль"}, status_code=403)
            ua = request.headers.get("user-agent", "")
            ip = request.client.host if request.client else ""
            token = _crm_repo.create_account_session(int(acc["id"]), ua, ip)
            a = dict(acc)
            return {"ok": True, "token": token,
                    "account": {"id": a["id"], "username": a["username"], "display_name": a.get("display_name", ""), "role": a["role"], "can_access_panel": a["can_access_panel"]}}
        except Exception as exc:
            return JSONResponse({"ok": False, "error": str(exc)}, status_code=500)

    @app.post("/api/account/logout")
    async def api_account_logout(request: Request):
        token = request.cookies.get("wp_session") or request.cookies.get("crm_session")
        if token and _crm_repo:
            try:
                _crm_repo.revoke_session(token)
            except Exception:
                pass
        return {"ok": True}

    @app.post("/api/verify_player_code")
    async def api_verify_player_code(request: Request):
        import secrets
        from server import database as _db
        body = await request.json()
        code = (body.get("code") or "").strip()
        if not code:
            return JSONResponse({"ok": False, "error": "Введите код"}, status_code=400)
        srv = _server_ref
        if not srv:
            return JSONResponse({"ok": False, "error": "Сервер не загружен"}, status_code=503)
        hdid = _db.get_hdid_by_player_code(code)
        if not hdid:
            return JSONResponse({"ok": False, "error": "Неверный код"}, status_code=403)
        token = secrets.token_hex(16)
        _player_code_sessions[token] = {"code": code, "created_at": __import__("time").time()}
        resp = JSONResponse({"ok": True, "token": token})
        resp.set_cookie("player_code_session", token, httponly=True, max_age=86400, samesite="lax")
        return resp

    @app.get("/api/account/profile")
    async def api_account_profile(request: Request):
        acc = _account(request)
        if not acc:
            return {"ok": False, "error": "Not logged in"}
        return {"ok": True, "account": {k: acc[k] for k in ("id","username","display_name","role","created_at","last_login_at")}}

    @app.post("/api/account/profile")
    async def api_account_profile_update(request: Request):
        acc = _account(request)
        if not acc:
            return JSONResponse({"error": "Not logged in"}, status_code=401)
        body = await request.json()
        display_name = (body.get("display_name") or "").strip()
        if display_name and _crm_repo:
            stamp = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
            with _crm_repo.crm_conn() as conn:
                conn.execute("UPDATE accounts SET display_name=?,updated_at=? WHERE id=?", (display_name, stamp, acc["id"]))
        return {"ok": True}

    @app.get("/api/account/list")
    async def api_account_list(request: Request):
        acc = _account(request)
        if not acc or not _role_at_least(acc, "ga"):
            return {"ok": False, "error": "Forbidden"}
        if not _crm_repo:
            return {"ok": False, "error": "CRM не загружен"}
        rows = _crm_repo.list_accounts()
        return {"ok": True, "accounts": [dict(r) for r in rows]}

    @app.post("/api/account/approve")
    async def api_account_approve(request: Request):
        acc = _account(request)
        if not acc or str(acc.get("role")) != "superadmin":
            return {"ok": False, "error": "Forbidden"}
        if not _crm_repo:
            return {"ok": False, "error": "CRM не загружен"}
        body = await request.json()
        _crm_repo.set_account_access(int(body["account_id"]), bool(body.get("grant", True)), int(acc["id"]))
        return {"ok": True}

    @app.post("/api/account/role")
    async def api_account_role(request: Request):
        acc = _account(request)
        if not acc:
            return {"ok": False, "error": "Forbidden"}
        if not _crm_repo:
            return {"ok": False, "error": "CRM не загружен"}
        body = await request.json()
        role = body.get("role", "user")
        if role not in ROLE_HIERARCHY:
            return {"ok": False, "error": "Недопустимая роль"}
        caller_role = str(acc.get("role"))
        caller_lvl = ROLE_HIERARCHY.get(caller_role, 0)
        target_lvl = ROLE_HIERARCHY.get(role, 0)
        # Only allow setting roles <= caller's level
        if target_lvl >= caller_lvl and caller_role != "superadmin":
            return {"ok": False, "error": "Нельзя назначить роль выше или равную своей"}
        stamp = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
        with _crm_repo.crm_conn() as conn:
            conn.execute("UPDATE accounts SET role=?,updated_at=? WHERE id=?", (role, stamp, int(body["account_id"])))
        return {"ok": True}

    # ─── CRM API ────────────────────────────────────────────────────

    @app.get("/api/crm/stats")
    async def crm_stats(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        if not _crm_repo:
            return {"ok": False, "error": "CRM не загружен"}
        with _crm_repo.crm_conn() as conn:
            players = conn.execute("SELECT COUNT(*) cnt FROM player_cache").fetchone()["cnt"]
        sync = _crm_repo.sync_status()
        return {"ok": True, "stats": {"players": players}, "sync": sync}

    @app.post("/api/crm/sync")
    async def crm_sync(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        if not _crm_repo:
            return {"ok": False, "error": "CRM не загружен"}
        body = await request.json() if request.headers.get("content-length") else {}
        force = body.get("force", False)
        ok = _crm_repo.start_background_sync(force_full=force)
        return {"ok": ok}

    @app.get("/api/crm/players")
    async def crm_players(request: Request, q: str = "", page: int = 1, sort: str = "last_seen_desc", profiled: bool = False, banned: bool = False):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        if not _crm_repo:
            return {"ok": False, "error": "CRM не загружен"}
        result = _crm_repo.list_players(query=q, page=page, sort=sort, only_profiled=profiled, only_banned=banned)
        # Convert Row objects to dicts
        items = []
        for row in result["items"]:
            d = dict(row)
            d["last_seen_fmt"] = (row["last_seen"] or "")[:16].replace("T", " ")
            items.append(d)
        return {"ok": True, "items": items, "total": result["total"], "page": result["page"], "pages": result["pages"]}

    @app.get("/api/crm/player/{hdid}")
    async def crm_player_detail(request: Request, hdid: str):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        if not _crm_repo:
            return {"ok": False, "error": "CRM не загружен"}
        summary = _crm_repo._summary_from_cache_row(None)
        with _crm_repo.crm_conn() as conn:
            row = conn.execute("SELECT * FROM player_cache WHERE hdid=?", (hdid,)).fetchone()
            if row:
                ipids = [r["ipid"] for r in conn.execute("SELECT ipid FROM player_cache_ipids WHERE hdid=?", (hdid,)).fetchall()]
                summary = _crm_repo._summary_from_cache_row(row, ipids)
            bans = _crm_repo.get_player_bans(hdid)
            profiles = _crm_repo.get_player_profiles(hdid)
            access = _crm_repo.get_player_access_rule(hdid)
        return {
            "ok": True,
            "player": {
                "hdid": hdid,
                "first_seen": summary.get("first_seen"),
                "last_seen": summary.get("last_seen"),
                "connect_count": summary.get("connect_count", 0),
                "failed_count": summary.get("failed_count", 0),
                "last_ip": summary.get("last_ip", ""),
                "last_ipid": summary.get("last_ipid"),
                "last_ooc_name": summary.get("last_ooc_name", ""),
                "last_ic_name": summary.get("last_ic_name", ""),
                "last_char_name": summary.get("last_char_name", ""),
                "last_hub_name": summary.get("last_hub_name", ""),
                "ooc_names": list(summary.get("ooc_names", set())),
                "ic_names": list(summary.get("ic_names", set())),
                "char_names": list(summary.get("char_names", set())),
                "hub_names": list(summary.get("hub_names", set())),
                "ip_addresses": list(summary.get("ip_addresses", set())),
                "known_ipids": list(summary.get("known_ipids", set())),
                "is_hdid_banned": summary.get("is_hdid_banned", 0),
                "is_ip_banned": summary.get("is_ip_banned", 0),
            },
            "bans": [dict(b) for b in bans],
            "profiles": [dict(p) for p in profiles],
            "access_rule": dict(access) if access else None,
        }

    @app.get("/api/crm/player/{hdid}/logs")
    async def crm_player_logs(request: Request, hdid: str, filter: str = "all", q: str = "", page: int = 1, limit: int = 30):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        if not _crm_repo:
            return {"ok": False, "error": "CRM не загружен"}
        logs = _crm_repo.get_player_logs(hdid, log_filter=filter, query=q, limit=500)
        total = len(logs)
        total_pages = max(1, math.ceil(total / limit))
        start = (page - 1) * limit
        page_logs = logs[start:start + limit]
        return {"ok": True, "logs": page_logs, "total": total, "page": page, "pages": total_pages}

    @app.get("/api/crm/player/{hdid}/connections")
    async def crm_player_connections(request: Request, hdid: str, limit: int = 30):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        if not _crm_repo:
            return {"ok": False, "error": "CRM не загружен"}
        conns = _crm_repo.get_player_connections(hdid, limit=limit)
        return {"ok": True, "connections": [dict(c) for c in conns]}

    @app.get("/api/crm/profiles")
    async def crm_profiles(request: Request, q: str = "", page: int = 1, sort: str = "updated_desc"):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        if not _crm_repo:
            return {"ok": False, "error": "CRM не загружен"}
        result = _crm_repo.list_profiles(query=q, page=page, sort=sort)
        items = [dict(r) for r in result["items"]]
        return {"ok": True, "items": items, "total": result["total"], "page": result["page"], "pages": result["pages"]}

    @app.get("/api/crm/profile/{profile_id}")
    async def crm_profile_get(request: Request, profile_id: int):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        if not _crm_repo:
            return {"ok": False, "error": "CRM не загружен"}
        profile = _crm_repo.get_profile(profile_id)
        if not profile:
            return {"ok": False, "error": "Not found"}
        hdids = _crm_repo.get_profile_hdids(profile_id)
        return {"ok": True, "profile": dict(profile), "hdids": [dict(h) for h in hdids]}

    @app.post("/api/crm/profile/save")
    async def crm_profile_save(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        if not _crm_repo:
            return {"ok": False, "error": "CRM не загружен"}
        body = await request.json()
        profile_id = _crm_repo.save_profile(body)
        return {"ok": True, "id": profile_id}

    @app.post("/api/crm/profile/delete")
    async def crm_profile_delete(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        if not _crm_repo:
            return {"ok": False, "error": "CRM не загружен"}
        body = await request.json()
        _crm_repo.delete_profile(int(body["id"]))
        return {"ok": True}

    # ─── CRM GA Access API ──────────────────────────────────────────

    def _crm_acc(request):
        """Helper: returns (account, error_response)"""
        acc = _account(request)
        if not acc or not _crm_repo:
            return None, JSONResponse({"error": "Unauthorized"}, status_code=401)
        if not _crm_repo.has_panel_access(acc):
            return None, JSONResponse({"error": "No access"}, status_code=403)
        return acc, None

    @app.get("/api/crm/player/{hdid}/access")
    async def crm_player_access(request: Request, hdid: str):
        acc, err = _crm_acc(request)
        if err: return err
        rule = _crm_repo.get_player_access_rule(hdid)
        can_open = _crm_repo.can_open_player_card(acc, hdid)
        pending = None
        if not can_open:
            with _crm_repo.crm_conn() as conn:
                row = conn.execute(
                    "SELECT id FROM player_access_requests WHERE hdid=? AND requester_account_id=? AND status='pending'",
                    (hdid, int(acc["id"]))
                ).fetchone()
                if row: pending = row["id"]
        return {
            "ok": True,
            "rule": dict(rule) if rule else None,
            "can_open": can_open,
            "is_superadmin": str(acc.get("role")) == "superadmin",
            "pending_request_id": pending,
        }

    @app.post("/api/crm/player/{hdid}/access-rule")
    async def crm_player_access_rule(request: Request, hdid: str):
        acc, err = _crm_acc(request)
        if err: return err
        if str(acc.get("role")) != "superadmin":
            return JSONResponse({"error": "Only superadmin"}, status_code=403)
        body = await request.json()
        requires = bool(body.get("requires_ga_accept", True))
        _crm_repo.set_player_access_rule(hdid, requires, int(acc["id"]))
        return {"ok": True}

    @app.post("/api/crm/player/{hdid}/access-request")
    async def crm_player_access_request(request: Request, hdid: str):
        acc, err = _crm_acc(request)
        if err: return err
        if str(acc.get("role")) == "superadmin":
            return {"ok": True, "granted": True}
        rule = _crm_repo.get_player_access_rule(hdid)
        if rule and int(rule["requires_ga_accept"] or 0):
            status, req_id = _crm_repo.request_player_access(hdid, int(acc["id"]))
            return {"ok": True, "request_id": req_id, "status": status, "granted": status == "approved"}
        return {"ok": True, "granted": True}

    @app.post("/api/crm/access-request/resolve")
    async def crm_access_request_resolve(request: Request):
        acc, err = _crm_acc(request)
        if err: return err
        if str(acc.get("role")) != "superadmin":
            return JSONResponse({"error": "Only superadmin"}, status_code=403)
        body = await request.json()
        _crm_repo.resolve_player_access_request(int(body["request_id"]), int(acc["id"]), bool(body.get("approve", True)))
        return {"ok": True}

    @app.post("/api/crm/player/session/start")
    async def crm_player_session_start(request: Request):
        acc, err = _crm_acc(request)
        if err: return err
        body = await request.json()
        hdid = body.get("hdid")
        if not hdid:
            return JSONResponse({"error": "Missing hdid"}, status_code=400)
        if not _crm_repo.can_open_player_card(acc, hdid):
            return JSONResponse({"error": "Access denied"}, status_code=403)
        ua = request.headers.get("user-agent", "")
        ip = request.client.host if request.client else ""
        _crm_repo.create_player_card_session(hdid, int(acc["id"]), ip, ua)
        return {"ok": True}

    @app.post("/api/crm/player/session/ping")
    async def crm_player_session_ping(request: Request):
        acc, err = _crm_acc(request)
        if err: return err
        body = await request.json()
        hdid = body.get("hdid")
        if hdid:
            try:
                _crm_repo.touch_player_card_session(hdid, int(acc["id"]))
            except Exception:
                pass
        return {"ok": True}

    @app.post("/api/crm/player/session/end")
    async def crm_player_session_end(request: Request):
        acc, err = _crm_acc(request)
        if err: return err
        body = await request.json()
        hdid = body.get("hdid")
        if hdid:
            try:
                _crm_repo.end_player_card_session(hdid, int(acc["id"]), body.get("reason", "manual"))
            except Exception:
                pass
        return {"ok": True}

    # ─── Approvals API ──────────────────────────────────────────────

    def _get_bot():
        srv = _server_ref
        if srv and hasattr(srv, "discord_bot") and srv.discord_bot is not None:
            return srv.discord_bot
        return None

    def _pending_approvals_list():
        bot = _get_bot()
        if not bot:
            return []
        items = []
        # Whitelist join requests
        for rid, req in bot.whitelist_pending_requests.items():
            items.append({
                "id": rid, "type": "wl_join",
                "info": f"Вайт-лист: HDID {req.get('hdid','?')} / IPID {req.get('ipid','?')}",
                "hdid": req.get("hdid",""), "ipid": req.get("ipid",""), "ip": req.get("ip",""),
                "created_at": "",
            })
        # GM requests
        for rid, req in bot.gm_pending_requests.items():
            client = req.get("client")
            name = getattr(client, "showname", getattr(client, "name", "?")) if client else "?"
            items.append({
                "id": rid, "type": "gm_request",
                "info": f"GM: {name} — {req.get('cmd','?')} {req.get('arg','')}",
                "name": name, "cmd": req.get("cmd",""), "arg": req.get("arg",""),
                "created_at": "",
            })
        # Login approval requests
        for rid, req in bot.login_pending_requests.items():
            client = req.client
            name = getattr(client, "showname", getattr(client, "name", "?")) if client else "?"
            items.append({
                "id": rid, "type": "login_approval",
                "info": f"Вход в админку: {name}",
                "name": name,
                "created_at": "",
            })
        return items

    @app.get("/api/approvals")
    async def api_approvals(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        items = _pending_approvals_list()
        return {"ok": True, "items": items, "total": len(items)}

    @app.post("/api/approvals/{req_type}/{request_id}/{action}")
    async def api_approval_resolve(request: Request, req_type: str, request_id: str, action: str):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        acc = _account(request)
        if not acc or str(acc.get("role")) not in ("admin", "superadmin"):
            return JSONResponse({"error": "Только админ"}, status_code=403)
        bot = _get_bot()
        if not bot:
            return JSONResponse({"ok": False, "error": "Discord бот не запущен"})
        approved = action == "approve"
        resolver = acc.get("display_name") or acc.get("username") or "Админ"
        row = {
            "id": request_id, "type": req_type,
            "status": "approved" if approved else "rejected",
            "resolved_by": resolver,
            "data": {},
        }
        # Copy data from the pending request
        if req_type == "wl_join":
            req = bot.whitelist_pending_requests.get(request_id, {})
            row["data"] = {"hdid": req.get("hdid",""), "ipid": req.get("ipid",""), "ip": req.get("ip",""),
                           "channel_id": req.get("channel_id"), "message_id": req.get("message_id")}
        elif req_type == "gm_request":
            req = bot.gm_pending_requests.get(request_id, {})
            client = req.get("client")
            row["data"] = {"client_id": getattr(client, "id", 0) if client else 0,
                           "arg": req.get("arg",""), "cmd": req.get("cmd",""),
                           "channel_id": req.get("channel_id"), "message_id": req.get("message_id")}
        elif req_type == "login_approval":
            req = bot.login_pending_requests.get(request_id)
            if req:
                client = req.client
                row["data"] = {"channel_id": req.channel_id, "message_id": req.message_id,
                               "reason": ""}
        try:
            loop = _server_ref.loop if _server_ref else None
            if loop:
                asyncio.run_coroutine_threadsafe(bot._process_site_resolution(row), loop)
            return {"ok": True, "message": f"{'Подтверждено' if approved else 'Отклонено'}"}
        except Exception as e:
            return JSONResponse({"ok": False, "error": str(e)})

    # ─── API ─────────────────────────────────────────────────────────

    @app.get("/api/ping")
    async def api_ping():
        return {"ok": True, "server": _server_ref is not None}

    @app.get("/api/stats")
    async def api_stats(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        srv = _server_ref
        cm = srv.client_manager
        areas = []
        for hub in srv.hub_manager.hubs:
            for area in hub.areas:
                areas.append({
                    "hub": hub.name, "hub_id": hub.id,
                    "id": area.id, "name": area.name,
                    "players": len([c for c in area.clients if c.char_id != -1]),
                    "music": area.music or "", "background": area.background or "",
                })
        players = []
        warn_counts = {}
        try:
            with _wp_db_conn as conn:
                for row in conn.execute("SELECT ipid, COUNT(*) as cnt FROM player_warnings GROUP BY ipid").fetchall():
                    warn_counts[row["ipid"]] = row["cnt"]
        except Exception:
            pass
        for c in cm.clients:
            wc = warn_counts.get(c.ipid, 0)
            players.append({
                "id": c.id, "name": c.name, "char_name": c.char_name,
                "area": c.area.name if c.area else "",
                "area_id": c.area.id if c.area else -1,
                "hub": c.area.area_manager.name if c.area and hasattr(c.area, "area_manager") else "",
                "ipid": c.ipid, "ip": getattr(c, "ip_address", ""),
                "hdid": getattr(c, "hdid", ""), "is_mod": c.is_mod,
                "is_muted": getattr(c, "is_muted", False),
                "is_ooc_muted": getattr(c, "is_ooc_muted", False),
                "disemvowel": getattr(c, "disemvowel", False),
                "shaken": getattr(c, "shaken", False),
                "rainbow": getattr(c, "rainbow", False),
                "char_id": c.char_id,
                "warnings_count": wc,
            })
        return {
            "player_count": srv.player_count, "total_clients": len(cm.clients),
            "uptime": time.time() - srv.start_time if hasattr(srv, "start_time") else 0,
            "areas": areas, "players": players,
            "mods": [p for p in players if p["is_mod"]],
            "hubs": [{"id": h.id, "name": h.name} for h in srv.hub_manager.hubs],
        }

    @app.get("/api/hubs")
    async def api_hubs(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        srv = _server_ref
        hubs = []
        for hub in srv.hub_manager.hubs:
            hubs.append({
                "id": hub.id, "name": hub.name,
                "areas": [{"id": a.id, "name": a.name} for a in hub.areas],
            })
        return {"hubs": hubs}

    @app.get("/api/logs")
    async def api_logs(request: Request, q: str = None, ipid: int = None, hub: int = None, area: int = None,
                       page: int = 1, limit: int = 50):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        offset = (page - 1) * limit
        where = ["t.type_name IN ('chat.ic','chat.ooc')"]
        params = []
        if q:
            where.append("a.message LIKE ?"); params.append(f"%{q}%")
        if ipid:
            where.append("a.ipid = ?"); params.append(ipid)
        if hub is not None:
            where.append("a.hub_id = ?"); params.append(hub)
        if area is not None:
            where.append("a.area_id = ?"); params.append(area)
        w = " AND ".join(where)
        with _wp_db_conn as conn:
            rows = conn.execute(
                f"""SELECT a.event_time, a.ipid, a.area_name, a.hub_name,
                           a.char_name, a.ooc_name, a.ic_name, a.message, t.type_name
                    FROM area_events a
                    JOIN area_event_types t ON a.event_subtype = t.type_id
                    WHERE {w}
                    ORDER BY a.event_time DESC LIMIT ? OFFSET ?""",
                (*params, limit, offset),
            ).fetchall()
            total = conn.execute(
                f"SELECT COUNT(*) FROM area_events a JOIN area_event_types t ON a.event_subtype = t.type_id WHERE {w}",
                params,
            ).fetchone()[0]
        items = []
        for r in rows:
            raw = str(r["event_time"])
            if raw and len(raw) >= 16:
                try:
                    dt = datetime.strptime(raw[:19], "%Y-%m-%d %H:%M:%S")
                    dt = dt + timedelta(hours=3)
                    raw = dt.strftime("%Y-%m-%d %H:%M:%S")
                except ValueError:
                    pass
            items.append({
                "time": raw, "ipid": r["ipid"],
                "area": r["area_name"], "hub": r["hub_name"],
                "char_name": r["char_name"], "ooc_name": r["ooc_name"],
                "ic_name": r["ic_name"],
                "message": r["message"], "type": r["type_name"],
            })
        return {"logs": items, "total": total, "page": page, "limit": limit}

    @app.get("/api/logs/find-page")
    async def api_logs_find_page(request: Request, time: str):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        try:
            dt = datetime.strptime(time[:19], "%Y-%m-%d %H:%M:%S")
            dt = dt - timedelta(hours=3)
            raw_time = dt.strftime("%Y-%m-%d %H:%M:%S")
        except (ValueError, IndexError):
            return JSONResponse({"error": "Invalid time"}, status_code=400)
        with _wp_db_conn as conn:
            cnt = conn.execute(
                """SELECT COUNT(*) FROM area_events a
                   JOIN area_event_types t ON a.event_subtype = t.type_id
                   WHERE t.type_name IN ('chat.ic','chat.ooc') AND a.event_time >= ?""",
                (raw_time,),
            ).fetchone()[0]
        page = max(1, math.ceil(cnt / 50))
        return {"page": page}

    @app.get("/api/player/{ipid}")
    async def api_player_info(ipid: int, request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        client = _cli(ipid)
        info = {"online": client is not None}
        if client:
            info.update({
                "id": client.id, "name": client.name,
                "char_name": client.char_name, "showname": getattr(client, "showname", ""),
                "area": client.area.name if client.area else "",
                "area_id": client.area.id if client.area else -1,
                "hub": client.area.area_manager.name if client.area and hasattr(client.area, "area_manager") else "",
                "ipid": client.ipid, "ip": getattr(client, "ip_address", ""),
                "hdid": getattr(client, "hdid", ""),
                "is_mod": client.is_mod, "is_muted": getattr(client, "is_muted", False),
                "is_ooc_muted": getattr(client, "is_ooc_muted", False),
                "disemvowel": getattr(client, "disemvowel", False),
                "shaken": getattr(client, "shaken", False),
                "rainbow": getattr(client, "rainbow", False),
                "char_id": client.char_id, "pos": getattr(client, "pos", ""),
            })
        with _wp_db_conn as conn:
            info["hdids"] = [r["hdid"] for r in conn.execute("SELECT hdid FROM hdids WHERE ipid=?", (ipid,)).fetchall()]
            name_rows = conn.execute(
                """SELECT ooc_name,COUNT(*) cnt FROM area_events WHERE ipid=? AND ooc_name IS NOT NULL AND ooc_name!=''
                   GROUP BY ooc_name ORDER BY cnt DESC LIMIT 20""", (ipid,)).fetchall()
            info["past_names"] = [{"name": r["ooc_name"], "count": r["cnt"]} for r in name_rows]
            stats = conn.execute("SELECT * FROM player_stats WHERE ipid=?", (ipid,)).fetchone()
            info["stats"] = dict(stats) if stats else {}
            # Если игрок онлайн, добавляем текущую сессию к времени
            if client and info["stats"].get("last_connect"):
                from datetime import datetime as dt2
                lc = info["stats"]["last_connect"]
                if isinstance(lc, str):
                    # Пробуем разные форматы — с микросекундами и без
                    for fmt in ("%Y-%m-%d %H:%M:%S.%f", "%Y-%m-%d %H:%M:%S"):
                        try:
                            lc = dt2.strptime(lc, fmt)
                            break
                        except ValueError:
                            continue
                    else:
                        lc = None
                if lc:
                    elapsed = int((dt2.utcnow() - lc).total_seconds())
                    if elapsed > 0:
                        info["stats"]["playtime_seconds"] = (info["stats"].get("playtime_seconds") or 0) + elapsed
            warns = conn.execute("SELECT * FROM player_warnings WHERE ipid=? ORDER BY warned_at DESC", (ipid,)).fetchall()
            info["warnings"] = [dict(r) for r in warns]
        return info

    @app.post("/api/player/kick")
    async def api_player_kick(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        ipid = int(body["ipid"])
        client = _cli(ipid)
        if not client:
            return JSONResponse({"ok": False, "error": "Игрок не в сети"})
        client.send_command("KB", body.get("reason", "Kicked via panel"))
        client.disconnect()
        _log_admin("kick", {"ipid": ipid, "name": client.name})
        return {"ok": True}

    @app.post("/api/player/ban")
    async def api_player_ban(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        ipid = int(body["ipid"])
        reason = body.get("reason", "Banned via panel")
        hours = int(body.get("hours", 0))
        unban_date = None
        if hours > 0:
            unban_date = (datetime.utcnow() + timedelta(hours=hours)).strftime("%Y-%m-%d %H:%M:%S")
        class _PB: name = "Panel"; ipid = 0
        ban_id = db.ban(ipid, reason, ban_type="ipid", banned_by=_PB(), unban_date=unban_date)
        client = _cli(ipid)
        if client:
            client.send_command("KB", reason)
            client.disconnect()
        _log_admin("ban", {"ipid": ipid, "reason": reason, "hours": hours, "ban_id": ban_id})
        broadcast_event("ban", {"time": time.strftime("%H:%M:%S"), "who": "Panel", "what": f"Забанил IPID {ipid}: {reason}"})
        return {"ok": True, "ban_id": ban_id}

    @app.post("/api/player/mute")
    async def api_player_mute(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        ipid = int(body["ipid"]); mtype = body.get("type", "ic")
        client = _cli(ipid)
        if not client:
            return JSONResponse({"ok": False, "error": "Игрок не в сети"})
        if mtype == "ic":
            client.is_muted = not client.is_muted; state = "muted" if client.is_muted else "unmuted"
        else:
            client.is_ooc_muted = not client.is_ooc_muted; state = "ooc_muted" if client.is_ooc_muted else "ooc_unmuted"
        _log_admin("mute", {"ipid": ipid, "type": mtype, "state": state})
        return {"ok": True, "state": state}

    @app.post("/api/player/fun")
    async def api_player_fun(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        ipid = int(body["ipid"]); cmd = body.get("command")
        client = _cli(ipid)
        if not client:
            return JSONResponse({"ok": False, "error": "Игрок не в сети"})
        toggles = {"disemvowel": "disemvowel", "shake": "shaken", "rainbow": "rainbow"}
        if cmd in toggles:
            attr = toggles[cmd]; old = getattr(client, attr, False); setattr(client, attr, not old)
            _log_admin(f"fun_{cmd}", {"ipid": ipid, "state": not old})
            return {"ok": True, "state": "включено" if not old else "выключено"}
        if cmd == "undisemvowel": client.disemvowel = False; return {"ok": True, "state": "выключено"}
        if cmd == "unshake": client.shaken = False; return {"ok": True, "state": "выключено"}
        return JSONResponse({"ok": False, "error": "Неизвестная команда"})

    @app.post("/api/player/impersonate_ic")
    async def api_impersonate_ic(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        ipid = int(body["ipid"]); message = body.get("message", "").strip()
        if not message:
            return JSONResponse({"ok": False, "error": "Пустое сообщение"})
        client = _cli(ipid)
        if not client:
            return JSONResponse({"ok": False, "error": "Игрок не в сети"})
        try:
            client.area.send_ic(client=client, msg=message, pos=client.pos, cid=client.char_id, showname=client.showname)
        except Exception as e:
            return JSONResponse({"ok": False, "error": str(e)})
        _log_admin("impersonate_ic", {"ipid": ipid, "name": client.name})
        return {"ok": True}

    @app.post("/api/player/impersonate_ooc")
    async def api_impersonate_ooc(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        ipid = int(body["ipid"]); message = body.get("message", "").strip()
        if not message:
            return JSONResponse({"ok": False, "error": "Пустое сообщение"})
        client = _cli(ipid)
        if not client:
            return JSONResponse({"ok": False, "error": "Игрок не в сети"})
        client.area.send_command("CT", f"[Panel]{client.name}", message)
        _log_admin("impersonate_ooc", {"ipid": ipid, "name": client.name})
        return {"ok": True}

    @app.post("/api/player/warn")
    async def api_player_warn(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        ipid = int(body["ipid"]); reason = body.get("reason", "").strip()
        if not reason:
            return JSONResponse({"ok": False, "error": "Укажите причину"})
        with _wp_db_conn as conn:
            conn.execute("INSERT INTO player_warnings(ipid, warned_by, reason) VALUES (?, 'Panel', ?)", (ipid, reason))
        client = _cli(ipid)
        if client:
            client.send_ooc(f"[Предупреждение] {reason}")
        _log_admin("warn", {"ipid": ipid, "reason": reason})
        return {"ok": True}

    @app.post("/api/player/warn/delete")
    async def api_player_warn_delete(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        ipid = int(body["ipid"]); wid = int(body["warning_id"])
        with _wp_db_conn as conn:
            conn.execute("DELETE FROM player_warnings WHERE id=? AND ipid=?", (wid, ipid))
        _log_admin("warn_delete", {"ipid": ipid, "warning_id": wid})
        return {"ok": True}

    @app.get("/api/achievements")
    async def api_achievements(request: Request, ipid: int = None):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        from server import achievements as ach
        if ipid:
            return {"achievements": ach.get_player_achievements(ipid)}
        return {"achievements": list(ach.get_all_defs().values())}

    @app.post("/api/achievements/grant")
    async def api_achievements_grant(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        ipid = int(body["ipid"]); achievement_id = body["achievement_id"]
        client = _cli(ipid)
        from server import achievements as ach
        with _wp_db_conn as conn:
            conn.execute("INSERT OR IGNORE INTO player_achievements(achievement_id, ipid) VALUES (?, ?)", (achievement_id, ipid))
        if client:
            adef = ach.get_achievement(achievement_id)
            if adef:
                client.send_ooc(f"[Достижение] Тебе выдано: {adef['name']} — {adef['description']}")
        _log_admin("achievement_grant", {"ipid": ipid, "achievement_id": achievement_id})
        return {"ok": True}

    @app.post("/api/achievements/revoke")
    async def api_achievements_revoke(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        ipid = int(body["ipid"]); achievement_id = body["achievement_id"]
        with _wp_db_conn as conn:
            conn.execute("DELETE FROM player_achievements WHERE achievement_id=? AND ipid=?", (achievement_id, ipid))
        _log_admin("achievement_revoke", {"ipid": ipid, "achievement_id": achievement_id})
        return {"ok": True}

    @app.post("/api/unban")
    async def api_unban(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        ok = db.unban(int(body["ban_id"]))
        return {"ok": ok}

    @app.post("/api/send_ooc")
    async def api_send_ooc(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        text = (body.get("text") or "").strip()
        if not text:
            return JSONResponse({"ok": False, "error": "Пустое сообщение"})
        _server_ref.send_all_cmd_pred("CT", "<dollar>G[Panel]", text)
        return {"ok": True}

    @app.get("/api/bans")
    async def api_bans(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        bans = []
        for b in db.recent_bans(50):
            bans.append({
                "ban_id": b.ban_id, "ban_date": str(b.ban_date),
                "unban_date": str(b.unban_date) if b.unban_date else None,
                "banned_by": b.banned_by, "banned_by_name": b.banned_by_name,
                "reason": b.reason,
            })
        return {"bans": bans}

    @app.get("/api/export/{type}")
    async def api_export(type: str, request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        srv = _server_ref
        out = io.StringIO()
        w = csv.writer(out)
        if type == "players":
            w.writerow(["ID", "OOC Name", "Character", "Area", "Hub", "IPID", "IP", "HDID", "Mod", "Muted"])
            for c in srv.client_manager.clients:
                w.writerow([c.id, c.name, c.char_name, c.area.name if c.area else "",
                            c.area.area_manager.name if c.area and hasattr(c.area, "area_manager") else "",
                            c.ipid, getattr(c, "ip_address", ""), getattr(c, "hdid", ""),
                            c.is_mod, getattr(c, "is_muted", False)])
            fn = "players.csv"
        elif type == "bans":
            w.writerow(["Ban ID", "Date", "Banner", "Reason", "Unban Date"])
            for b in db.recent_bans(200):
                w.writerow([b.ban_id, str(b.ban_date), b.banned_by_name or "", b.reason, str(b.unban_date) if b.unban_date else ""])
            fn = "bans.csv"
        elif type == "logs":
            w.writerow(["Time", "Type", "OOC Name", "Char Name", "Area", "Hub", "Message"])
            with _wp_db_conn as conn:
                rows = conn.execute(
                    """SELECT a.event_time, t.type_name, a.ooc_name, a.char_name, a.area_name, a.hub_name, a.message
                       FROM area_events a JOIN area_event_types t ON a.event_subtype = t.type_id
                       WHERE t.type_name IN ('chat.ic','chat.ooc') ORDER BY a.event_time DESC LIMIT 2000"""
                ).fetchall()
                for r in rows:
                    w.writerow([r[0], r[1], r[2] or "", r[3] or "", r[4] or "", r[5] or "", r[6] or ""])
            fn = "logs.csv"
        else:
            return JSONResponse({"error": "Unknown type"}, status_code=400)
        out.seek(0)
        return StreamingResponse(iter([out.getvalue()]), media_type="text/csv",
                                 headers={"Content-Disposition": f"attachment; filename={fn}"})

    @app.post("/api/execute")
    async def api_execute(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        cmd_text = body.get("command", "").strip()
        if not cmd_text:
            return JSONResponse({"ok": False, "error": "Пустая команда"})
        srv = _server_ref
        try:
            if cmd_text.startswith("/"):
                cmd_text = cmd_text[1:]
            parts = cmd_text.split(None, 1)
            cmd_name = parts[0].lower() if parts else ""
            cmd_arg = parts[1] if len(parts) > 1 else ""
            from server import commands as cmd_mod
            import types
            mock = types.SimpleNamespace(
                server=srv, is_mod=True, name="Panel", char_name="Panel",
                area=srv.hub_manager.hubs[0].areas[0] if srv.hub_manager.hubs else None,
                send_ooc=lambda msg: srv.send_all_cmd_pred("CT", "<dollar>G[Panel]", msg),
                send_command=lambda *a: None,
                ipid=0, hdid="",
                muted_global=False, muted_adverts=False,
                is_muted=False, is_ooc_muted=False, pm_mute=False,
                disemvowel=False, shaken=False, rainbow=False,
            )
            try:
                cmd_mod.call(mock, cmd_name, cmd_arg)
                _log_admin("execute", {"command": cmd_text})
                return {"ok": True, "result": "Команда выполнена"}
            except Exception as e:
                return {"ok": True, "result": f"Ошибка: {e}"}
        except Exception as e:
            return {"ok": False, "error": str(e)}

    # ─── Room Map API ────────────────────────────────
    @app.get("/api/room-map")
    async def api_room_map(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        srv = _server_ref
        hubs = []
        for hub in srv.hub_manager.hubs:
            areas_list = []
            for area in hub.areas:
                areas_list.append({
                    "id": area.id, "name": area.name,
                    "players": len([c for c in area.clients if c.char_id != -1]),
                    "music": area.music or "",
                    "background": area.background or "",
                    "status": getattr(area, "status", "IDLE"),
                    "desc": getattr(area, "desc", ""),
                })
            hubs.append({"id": hub.id, "name": hub.name, "areas": areas_list})
        return {"hubs": hubs}

    def _save_areas():
        """Сохранить хабы/зоны в config/areas.yaml для переживания рестарта."""
        srv = _server_ref
        if srv and hasattr(srv, "hub_manager"):
            try:
                srv.hub_manager.save("config/areas.yaml")
            except Exception as e:
                logger.warning("Auto-save areas failed: %s", e)

    # ─── Room Editor ─────────────────────────────────
    @app.post("/api/area/update")
    async def api_area_update(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        area_id = int(body["area_id"])
        hub_id = int(body.get("hub_id", -1))
        srv = _server_ref
        try:
            hub = srv.hub_manager.get_hub_by_id(hub_id)
            area = hub.get_area_by_id(area_id)
            if "music" in body:
                area.music = body["music"]
            if "background" in body:
                area.background = body["background"]
            _log_admin("area_update", {"hub_id": hub_id, "area_id": area_id, "changes": {k: body[k] for k in ("music","background") if k in body}})
            _save_areas()
            return {"ok": True, "area": {"id": area.id, "name": area.name, "music": area.music, "background": area.background}}
        except Exception as e:
            return JSONResponse({"ok": False, "error": "Зона не найдена"}, status_code=404)

    # ─── Hub/Area Management ─────────────────────────
    @app.post("/api/area/rename")
    async def api_area_rename(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        area_id = int(body.get("area_id", -1))
        hub_id = int(body.get("hub_id", -1))
        name = (body.get("name") or "").strip()
        if not name:
            return JSONResponse({"ok": False, "error": "Укажите название"})
        srv = _server_ref
        try:
            hub = srv.hub_manager.get_hub_by_id(hub_id)
            area = hub.get_area_by_id(area_id)
            area.name = name
            _log_admin("area_rename", {"hub_id": hub_id, "area_id": area_id, "name": name})
            _save_areas()
            return {"ok": True}
        except Exception as e:
            return JSONResponse({"ok": False, "error": "Зона не найдена"}, status_code=404)

    @app.post("/api/hub/create")
    async def api_hub_create(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        name = (body.get("name") or "").strip()
        if not name:
            return JSONResponse({"ok": False, "error": "Укажите название хаба"})
        srv = _server_ref
        hm = srv.hub_manager
        import importlib
        import server.area_manager as am
        new_hub = am.AreaManager(hm, name)
        hm.hubs.append(new_hub)
        _log_admin("hub_create", {"name": name})
        _save_areas()
        return {"ok": True, "hub": {"id": new_hub.id, "name": new_hub.name}}

    @app.post("/api/hub/rename")
    async def api_hub_rename(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        hub_id = int(body.get("hub_id", -1))
        name = (body.get("name") or "").strip()
        if not name:
            return JSONResponse({"ok": False, "error": "Укажите название"})
        srv = _server_ref
        try:
            hub = srv.hub_manager.get_hub_by_id(hub_id)
            hub.name = name
            _log_admin("hub_rename", {"hub_id": hub_id, "name": name})
            _save_areas()
            return {"ok": True}
        except Exception as e:
            return JSONResponse({"ok": False, "error": str(e)})

    @app.post("/api/area/create")
    async def api_area_create(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        hub_id = int(body.get("hub_id", -1))
        name = (body.get("name") or "").strip()
        if not name:
            return JSONResponse({"ok": False, "error": "Укажите название зоны"})
        srv = _server_ref
        try:
            hub = srv.hub_manager.get_hub_by_id(hub_id)
            import server.area as ar
            new_area = ar.Area(hub, name)
            hub.areas.append(new_area)
            _log_admin("area_create", {"hub_id": hub_id, "name": name})
            _save_areas()
            try:
                hub.broadcast_area_list(refresh=True)
            except Exception:
                pass
            return {"ok": True, "area": {"id": new_area.id, "name": new_area.name}}
        except Exception as e:
            return JSONResponse({"ok": False, "error": str(e)})

    @app.post("/api/area/delete")
    async def api_area_delete(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        area_id = int(body.get("area_id", -1))
        hub_id = int(body.get("hub_id", -1))
        srv = _server_ref
        try:
            hub = srv.hub_manager.get_hub_by_id(hub_id)
            area = hub.get_area_by_id(area_id)
            hub.remove_area(area)
            _log_admin("area_delete", {"hub_id": hub_id, "area_id": area_id})
            _save_areas()
            try:
                hub.broadcast_area_list(refresh=True)
            except Exception:
                pass
            return {"ok": True}
        except Exception as e:
            return JSONResponse({"ok": False, "error": "Зона не найдена"}, status_code=404)

    @app.post("/api/hub/delete")
    async def api_hub_delete(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        hub_id = int(body.get("hub_id", -1))
        srv = _server_ref
        if hub_id == 0:
            return JSONResponse({"ok": False, "error": "Нельзя удалить первый хаб"})
        for i, hub in enumerate(srv.hub_manager.hubs):
            if hub.id == hub_id:
                clients = hub.clients.copy()
                for c in clients:
                    c.set_area(srv.hub_manager.hubs[0].default_area())
                srv.hub_manager.hubs.pop(i)
                _log_admin("hub_delete", {"hub_id": hub_id})
                _save_areas()
                return {"ok": True}
        return JSONResponse({"ok": False, "error": "Хаб не найден"}, status_code=404)

    @app.get("/api/area/{area_id}/prefs")
    async def api_area_prefs(area_id: int, request: Request, hub_id: int = -1):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        srv = _server_ref
        try:
            hub = srv.hub_manager.get_hub_by_id(hub_id)
            area = hub.get_area_by_id(area_id)
            bools = {}
            for key, val in area.__dict__.items():
                if isinstance(val, bool):
                    bools[key] = val
            return {"prefs": bools}
        except Exception as e:
            return JSONResponse({"ok": False, "error": "Зона не найдена"}, status_code=404)

    @app.post("/api/area/{area_id}/prefs")
    async def api_area_prefs_save(area_id: int, request: Request, hub_id: int = -1):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        prefs = body.get("prefs", {})
        hub_id = body.get("hub_id", hub_id)
        srv = _server_ref
        try:
            hub = srv.hub_manager.get_hub_by_id(hub_id)
            area = hub.get_area_by_id(area_id)
            for key, val in prefs.items():
                if hasattr(area, key) and isinstance(getattr(area, key), bool):
                    setattr(area, key, bool(val))
            _log_admin("area_prefs", {"hub_id": hub_id, "area_id": area_id, "prefs": prefs})
            _save_areas()
            return {"ok": True}
        except Exception as e:
            return JSONResponse({"ok": False, "error": "Зона не найдена"}, status_code=404)

    @app.post("/api/area/bulk-update")
    async def api_area_bulk_update(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        area_id = int(body.get("area_id", -1))
        hub_id = int(body.get("hub_id", -1))
        srv = _server_ref
        try:
            hub = srv.hub_manager.get_hub_by_id(hub_id)
            area = hub.get_area_by_id(area_id)
            for key in ("background", "music", "desc", "status"):
                if key in body:
                    setattr(area, key, body[key])
            _log_admin("area_bulk_update", {"hub_id": hub_id, "area_id": area_id, "changes": body})
            _save_areas()
            return {"ok": True}
        except Exception as e:
            return JSONResponse({"ok": False, "error": "Зона не найдена"}, status_code=404)

    # ─── Player Messages (Surveillance) ─────────────
    @app.get("/api/player/{ipid}/messages")
    async def api_player_messages(ipid: int, request: Request, limit: int = 50):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            rows = conn.execute(
                """SELECT a.event_time, t.type_name, a.area_name, a.hub_name,
                          a.char_name, a.ooc_name, a.message
                   FROM area_events a JOIN area_event_types t ON a.event_subtype = t.type_id
                   WHERE a.ipid = ? AND t.type_name IN ('chat.ic','chat.ooc')
                   ORDER BY a.event_time DESC LIMIT ?""",
                (ipid, limit),
            ).fetchall()
        messages = []
        for r in reversed(rows):
            messages.append({
                "time": str(r["event_time"]), "type": r["type_name"],
                "area": r["area_name"], "hub": r["hub_name"],
                "char_name": r["char_name"], "ooc_name": r["ooc_name"],
                "message": r["message"],
            })
        return {"messages": messages}

    # ─── Connection Graph ───────────────────────────
    @app.get("/api/connections/{ipid}")
    async def api_connections(ipid: int, request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            hdids = [r["hdid"] for r in conn.execute("SELECT hdid FROM hdids WHERE ipid=?", (ipid,)).fetchall()]
            related = set()
            edges = []
            nodes = [{"id": f"ipid:{ipid}", "label": f"IPID {ipid}", "type": "central"}]
            for hdid in hdids:
                nodes.append({"id": f"hdid:{hdid}", "label": f"HDID {hdid[:16]}...", "type": "hdid"})
                edges.append({"source": f"ipid:{ipid}", "target": f"hdid:{hdid}"})
                others = conn.execute("SELECT ipid FROM hdids WHERE hdid=? AND ipid!=?", (hdid, ipid)).fetchall()
                for o in others:
                    oid = o["ipid"]
                    if oid not in related:
                        related.add(oid)
                        nodes.append({"id": f"ipid:{oid}", "label": f"IPID {oid}", "type": "related"})
                        edges.append({"source": f"hdid:{hdid}", "target": f"ipid:{oid}"})
                        name_row = conn.execute(
                            "SELECT ooc_name FROM area_events WHERE ipid=? AND ooc_name IS NOT NULL AND ooc_name!='' ORDER BY event_time DESC LIMIT 1",
                            (oid,)).fetchone()
                        if name_row:
                            nodes[-1]["label"] = f"{name_row['ooc_name']} (IPID {oid})"
        return {"nodes": nodes, "edges": edges}

    # ─── Leaderboard ────────────────────────────────
    @app.get("/api/leaderboard")
    async def api_leaderboard(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        return {"leaders": []}

    # ─── Dashboard Config (Widgets) ─────────────────
    @app.get("/api/dashboard/config")
    async def api_dashboard_config(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        try:
            with _wp_db_conn as conn:
                row = conn.execute("SELECT event_data FROM misc_events WHERE event_subtype IN (SELECT type_id FROM misc_event_types WHERE type_name='dashboard_config') ORDER BY event_time DESC LIMIT 1").fetchone()
                if row:
                    return {"config": json.loads(row["event_data"])}
        except Exception:
            pass
        return {"config": {"widgets": ["graph", "areas", "hubs", "ooc", "leaderboard"]}}

    @app.post("/api/dashboard/config")
    async def api_dashboard_config_save(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        db.log_misc("dashboard_config", data=body.get("config", {}))
        return {"ok": True}

    # ─── Analytics page ────────────────────────────────────────────
    @app.get("/analytics")
    async def analytics_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if not _role_at_least(acc, "ga"):
            return render("login.html", request=request, error="Доступ запрещён")
        return render("analytics.html", request=request)

    @app.get("/api/analytics/timeline")
    async def api_analytics_timeline(request: Request, days: int = 14):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            rows = conn.execute(
                """SELECT DATE(event_time) AS day, COUNT(DISTINCT ipid) AS cnt
                   FROM connect_events WHERE event_time >= DATE('now', ?)
                   GROUP BY day ORDER BY day""",
                (f"-{days} days",),
            ).fetchall()
        return {"timeline": [{"day": r["day"], "count": r["cnt"]} for r in rows]}

    @app.get("/api/analytics/activity")
    async def api_analytics_activity(request: Request, days: int = 7):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            hourly = conn.execute(
                """SELECT CAST(STRFTIME('%H',event_time) AS INTEGER) AS hour,
                          COUNT(*) AS cnt FROM area_events
                   WHERE event_time >= datetime('now',?)
                   AND event_subtype IN (SELECT type_id FROM area_event_types WHERE type_name IN ('chat.ic','chat.ooc'))
                   GROUP BY hour ORDER BY hour""",
                (f"-{days} days",),
            ).fetchall()
            daily = conn.execute(
                """SELECT DATE(event_time) AS day, COUNT(*) AS cnt FROM area_events
                   WHERE event_time >= datetime('now',?)
                   AND event_subtype IN (SELECT type_id FROM area_event_types WHERE type_name IN ('chat.ic','chat.ooc'))
                   GROUP BY day ORDER BY day""",
                (f"-{days} days",),
            ).fetchall()
            top_areas = conn.execute(
                """SELECT ae.area_name, ae.hub_name, COUNT(*) AS cnt FROM area_events ae
                   WHERE ae.event_time >= datetime('now',?)
                   AND ae.event_subtype IN (SELECT type_id FROM area_event_types WHERE type_name IN ('chat.ic','chat.ooc'))
                   GROUP BY ae.area_name, ae.hub_name ORDER BY cnt DESC LIMIT 15""",
                (f"-{days} days",),
            ).fetchall()
        return {
            "hourly": [{"hour": r["hour"], "count": r["cnt"]} for r in hourly],
            "daily": [{"day": r["day"], "count": r["cnt"]} for r in daily],
            "top_areas": [{"name": r["area_name"], "hub": r["hub_name"], "count": r["cnt"]} for r in top_areas],
        }

    @app.get("/api/analytics/players")
    async def api_analytics_players(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            total = conn.execute("SELECT COUNT(*) FROM player_stats").fetchone()[0]
            with_ic = conn.execute("SELECT COUNT(*) FROM player_stats WHERE ic_messages>0").fetchone()[0]
            total_msgs = conn.execute("SELECT COALESCE(SUM(ic_messages+ooc_messages),0) FROM player_stats").fetchone()[0]
            avg_play = conn.execute("SELECT COALESCE(AVG(playtime_seconds),0) FROM player_stats").fetchone()[0]
        return {"total_players": total, "active_ic": with_ic, "total_messages": total_msgs, "avg_playtime": round(avg_play / 3600, 1)}

    # ─── Audit log page ────────────────────────────────────────────
    @app.get("/admin/audit")
    async def audit_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        if not _role_at_least(_account(request), "moderator"):
            return RedirectResponse(url="/")
        return render("admin_audit.html", request=request)

    @app.get("/api/admin/log")
    async def api_admin_log(request: Request, page: int = 1, limit: int = 50):
        acc = _account(request)
        if not acc or not _role_at_least(acc, "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        offset = (page - 1) * limit
        with _wp_db_conn as conn:
            subtype = conn.execute("SELECT type_id FROM misc_event_types WHERE type_name='admin_action'").fetchone()
            if not subtype:
                return {"items": [], "total": 0, "page": page}
            tid = subtype[0]
            rows = conn.execute(
                """SELECT m.event_time, m.ipid, m.target_ipid, m.event_data
                   FROM misc_events m WHERE m.event_subtype=?
                   ORDER BY m.event_time DESC LIMIT ? OFFSET ?""",
                (tid, limit, offset),
            ).fetchall()
            total = conn.execute("SELECT COUNT(*) FROM misc_events WHERE event_subtype=?", (tid,)).fetchone()[0]
        items = []
        for r in rows:
            data = json.loads(r["event_data"]) if r["event_data"] else {}
            items.append({
                "time": str(r["event_time"])[:19] if r["event_time"] else "",
                "ipid": r["ipid"],
                "target_ipid": r["target_ipid"],
                "action": data.get("action", ""),
                "details": {k: v for k, v in data.items() if k != "action"},
            })
        return {"items": items, "total": total, "page": page, "pages": max(1, -(-total // limit))}

    # ─── Backups page ──────────────────────────────────────────────
    BACKUP_DIR = HERE.parent.parent / "backups"
    BACKUP_DIR.mkdir(exist_ok=True)

    @app.get("/admin/backups")
    async def backups_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        if not _role_at_least(_account(request), "superadmin"):
            return RedirectResponse(url="/")
        return render("admin_backups.html", request=request)

    @app.get("/api/backups")
    async def api_backups_list(request: Request):
        if not _role_at_least(_account(request), "superadmin"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        files = sorted(BACKUP_DIR.iterdir(), key=lambda f: f.stat().st_mtime, reverse=True) if BACKUP_DIR.exists() else []
        items = []
        for f in files:
            if f.suffix in (".db", ".sqlite3", ".sqlite"):
                items.append({
                    "name": f.name,
                    "size": f.stat().st_size,
                    "modified": datetime.fromtimestamp(f.stat().st_mtime).strftime("%Y-%m-%d %H:%M:%S"),
                })
        return {"items": items}

    @app.post("/api/backups/create")
    async def api_backups_create(request: Request):
        if not _role_at_least(_account(request), "superadmin"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        stamp = datetime.utcnow().strftime("%Y%m%d_%H%M%S")
        db_path = HERE.parent.parent / "storage" / "db.sqlite3"
        if not db_path.exists():
            return {"ok": False, "error": "База не найдена"}
        backup_name = f"backup_{stamp}.db"
        backup_path = BACKUP_DIR / backup_name
        try:
            import shutil
            shutil.copy2(str(db_path), str(backup_path))
            _log_admin("backup_create", {"name": backup_name})
            return {"ok": True, "name": backup_name}
        except Exception as e:
            return {"ok": False, "error": str(e)}

    @app.get("/api/backups/download/{name}")
    async def api_backups_download(request: Request, name: str):
        if not _role_at_least(_account(request), "superadmin"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        fpath = BACKUP_DIR / name
        if not fpath.exists() or not fpath.is_file():
            return JSONResponse({"error": "Not found"}, status_code=404)
        return FileResponse(str(fpath), filename=name, media_type="application/octet-stream")

    @app.post("/api/backups/delete")
    async def api_backups_delete(request: Request):
        if not _role_at_least(_account(request), "superadmin"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        name = body.get("name", "")
        fpath = BACKUP_DIR / name
        if fpath.exists() and fpath.is_file():
            fpath.unlink()
            return {"ok": True}
        return {"ok": False, "error": "Not found"}

    # ─── Achievement progress page ──────────────────────────────────
    @app.get("/achievements")
    async def achievements_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if not _role_at_least(acc, "ga"):
            return render("login.html", request=request, error="Доступ запрещён")
        return render("achievements.html", request=request)

    @app.get("/api/achievements/progress")
    async def api_achievements_progress(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            total_players = conn.execute("SELECT COUNT(*) FROM player_stats").fetchone()[0]
            defs = conn.execute("SELECT * FROM achievement_defs ORDER BY category, id").fetchall()
            progress = []
            for d in defs:
                cnt = conn.execute(
                    "SELECT COUNT(*) FROM player_achievements WHERE achievement_id=?",
                    (d["id"],),
                ).fetchone()[0]
                pct = round(cnt / total_players * 100, 1) if total_players else 0
                progress.append({
                    "id": d["id"],
                    "name": d["name"],
                    "description": d["description"],
                    "category": d["category"],
                    "criteria": d["criteria_type"],
                    "threshold": d["criteria_value"],
                    "unlocked_count": cnt,
                    "total_players": total_players,
                    "percent": pct,
                })
        return {"progress": progress}

    # ─── Ensure custom tables ─────────────────────────────────
    with _wp_db_conn as conn:
        conn.executescript("""
        CREATE TABLE IF NOT EXISTS scheduled_events(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT '',
            area_name TEXT DEFAULT '',
            hub_name TEXT DEFAULT '',
            event_time TEXT NOT NULL,
            duration_minutes INTEGER DEFAULT 60,
            creator_ipid INTEGER,
            notify_before INTEGER DEFAULT 10,
            created_at TEXT DEFAULT (datetime('now')),
            status TEXT DEFAULT 'pending'
        );
        CREATE TABLE IF NOT EXISTS player_groups(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT DEFAULT '',
            leader_ipid INTEGER,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS player_group_members(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL,
            ipid INTEGER NOT NULL,
            role TEXT DEFAULT 'member',
            UNIQUE(group_id, ipid),
            FOREIGN KEY (group_id) REFERENCES player_groups(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS quests(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT '',
            trigger_type TEXT DEFAULT 'manual',
            trigger_config TEXT DEFAULT '{}',
            stages TEXT DEFAULT '[]',
            rewards TEXT DEFAULT '{}',
            enabled INTEGER DEFAULT 1,
            created_by_ipid INTEGER,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS bulletin_posts(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            content TEXT DEFAULT '',
            created_by_ipid INTEGER,
            created_at TEXT DEFAULT (datetime('now')),
            expires_at TEXT,
            pinned INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS word_filters(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pattern TEXT NOT NULL,
            replacement TEXT DEFAULT '***',
            hub_name TEXT DEFAULT '',
            area_name TEXT DEFAULT '',
            enabled INTEGER DEFAULT 1,
            warn_on_match INTEGER DEFAULT 0,
            created_by_ipid INTEGER,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS shop_items(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT '',
            price INTEGER DEFAULT 0,
            item_type TEXT DEFAULT 'item',
            item_data TEXT DEFAULT '{}',
            stock INTEGER DEFAULT -1,
            enabled INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS shop_purchases(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            ipid INTEGER NOT NULL,
            purchased_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (item_id) REFERENCES shop_items(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS player_notes(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_ipid INTEGER NOT NULL,
            note TEXT NOT NULL,
            created_by_ipid INTEGER,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS automod_configs(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hub_name TEXT DEFAULT '',
            area_name TEXT DEFAULT '',
            enabled INTEGER DEFAULT 1,
            flood_msg_limit INTEGER DEFAULT 5,
            flood_window_secs INTEGER DEFAULT 3,
            caps_percent INTEGER DEFAULT 70,
            caps_min_len INTEGER DEFAULT 5,
            repeat_count INTEGER DEFAULT 3,
            repeat_window_secs INTEGER DEFAULT 10,
            action TEXT DEFAULT 'warn',
            check_word_filter INTEGER DEFAULT 1,
            created_by_ipid INTEGER,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS player_warnings(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ipid INTEGER NOT NULL,
            warned_by TEXT DEFAULT 'Panel',
            reason TEXT NOT NULL,
            warned_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS polls(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question TEXT NOT NULL,
            created_by_ipid INTEGER,
            created_at TEXT DEFAULT (datetime('now')),
            expires_at TEXT,
            closed INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS poll_votes(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id INTEGER NOT NULL,
            ipid INTEGER NOT NULL,
            vote TEXT NOT NULL,
            voted_at TEXT DEFAULT (datetime('now')),
            UNIQUE(poll_id, ipid),
            FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
        );
        """)
        # Migrate automod_configs — add new columns if missing
        for col, col_def in [("action", "TEXT DEFAULT 'warn'"), ("check_word_filter", "INTEGER DEFAULT 1")]:
            try:
                conn.execute(f"ALTER TABLE automod_configs ADD COLUMN {col} {col_def}")
            except Exception:
                pass
        # Migrate word_filters — add warn_on_match if missing
        try:
            conn.execute("ALTER TABLE word_filters ADD COLUMN warn_on_match INTEGER DEFAULT 0")
        except Exception:
            pass
    
    # ─── Event scheduler page ─────────────────────────────────
    @app.get("/events")
    async def events_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if not _role_at_least(acc, "ga"):
            return render("login.html", request=request, error="Доступ запрещён")
        return render("events.html", request=request)

    @app.get("/api/events")
    async def api_events_list(request: Request, page: int = 1, limit: int = 50):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        offset = (page - 1) * limit
        with _wp_db_conn as conn:
            rows = conn.execute(
                "SELECT * FROM scheduled_events ORDER BY event_time DESC LIMIT ? OFFSET ?",
                (limit, offset),
            ).fetchall()
            total = conn.execute("SELECT COUNT(*) FROM scheduled_events").fetchone()[0]
        return {"items": [dict(r) for r in rows], "total": total, "page": page}

    @app.post("/api/events")
    async def api_events_create(request: Request):
        acc = _account(request)
        if not acc:
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        with _wp_db_conn as conn:
            conn.execute(
                "INSERT INTO scheduled_events(name,description,area_name,hub_name,event_time,duration_minutes,notify_before,creator_ipid,status) VALUES (?,?,?,?,?,?,?,?,'pending')",
                (body["name"], body.get("description", ""), body.get("area_name", ""),
                 body.get("hub_name", ""), body["event_time"], int(body.get("duration_minutes", 60)),
                 int(body.get("notify_before", 10)), acc.get("id")),
            )
        _log_admin("event_create", {"name": body["name"], "time": body["event_time"]})
        return {"ok": True}

    @app.post("/api/events/{event_id}")
    async def api_events_update(request: Request, event_id: int):
        acc = _account(request)
        if not acc:
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        sets = []; params = []
        for k in ("name","description","area_name","hub_name","event_time","duration_minutes","notify_before","status"):
            if k in body:
                sets.append(f"{k}=?"); params.append(body[k])
        if not sets:
            return {"ok": False, "error": "No fields"}
        params.append(event_id)
        with _wp_db_conn as conn:
            conn.execute(f"UPDATE scheduled_events SET {','.join(sets)} WHERE id=?", params)
        return {"ok": True}

    @app.post("/api/events/{event_id}/delete")
    async def api_events_delete(request: Request, event_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        with _wp_db_conn as conn:
            conn.execute("DELETE FROM scheduled_events WHERE id=?", (event_id,))
        return {"ok": True}

    # ─── Server settings page ─────────────────────────────────
    CONFIG_PATH = HERE.parent.parent / "config" / "config.yaml"
    AREAS_PATH = HERE.parent.parent / "config" / "areas.yaml"
    MUSIC_PATH = HERE.parent.parent / "config" / "music.yaml"

    @app.get("/admin/settings")
    async def settings_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        if not _role_at_least(_account(request), "admin"):
            return RedirectResponse(url="/")
        return render("admin_settings.html", request=request)

    @app.get("/api/admin/settings")
    async def api_settings_get(request: Request):
        if not _role_at_least(_account(request), "admin"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        import yaml
        try:
            with open(CONFIG_PATH, encoding="utf-8") as f:
                cfg = yaml.safe_load(f)
        except Exception as e:
            return {"ok": False, "error": str(e)}
        fields = {k: cfg.get(k, "") for k in ("hostname","playerlimit","port","modpass","motd","use_websockets","websocket_port","debug","timeout","login_approval")}
        fields["_areas_count"] = len(cfg.get("areas", []))
        fields["_music_count"] = len(cfg.get("music", []))
        return {"ok": True, "config": fields}

    @app.post("/api/admin/settings")
    async def api_settings_save(request: Request):
        if not _role_at_least(_account(request), "admin"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        import yaml
        body = await request.json()
        try:
            with open(CONFIG_PATH, encoding="utf-8") as f:
                cfg = yaml.safe_load(f) or {}
        except Exception:
            cfg = {}
        for k in ("hostname","playerlimit","port","motd","debug","timeout","login_approval"):
            if k in body:
                cfg[k] = body[k]
        with open(CONFIG_PATH, "w", encoding="utf-8") as f:
            yaml.dump(cfg, f, default_flow_style=False, allow_unicode=True)
        srv = _server_ref
        if srv and body.get("motd"):
            try:
                srv.send_all_cmd_pred("CT", body["motd"])
            except Exception:
                pass
        _log_admin("config_save", {k: body[k] for k in body if k in ("hostname","playerlimit","motd")})
        return {"ok": True}

    # ─── Performance monitor page ──────────────────────────────
    @app.get("/admin/perf")
    async def perf_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if not _role_at_least(acc, "ga"):
            return render("login.html", request=request, error="Доступ запрещён")
        return render("admin_perf.html", request=request)

    @app.get("/api/admin/perf")
    async def api_perf(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        srv = _server_ref
        clients = len(srv.client_manager.clients) if srv and srv.client_manager else 0
        hubs = len(srv.hub_manager.hubs) if srv and srv.hub_manager else 0
        areas = sum(len(h.areas) for h in srv.hub_manager.hubs) if srv and srv.hub_manager else 0
        uptime = int(time.time() - srv.start_time) if srv else 0
        try:
            db_path = HERE.parent.parent / "storage" / "db.sqlite3"
            db_size = db_path.stat().st_size if db_path.exists() else 0
        except Exception:
            db_size = 0
        with _wp_db_conn as conn:
            msg_h = conn.execute(
                """SELECT COUNT(*) FROM area_events
                   WHERE event_time >= datetime('now','-1 hour')
                   AND event_subtype IN (SELECT type_id FROM area_event_types WHERE type_name IN ('chat.ic','chat.ooc'))"""
            ).fetchone()[0]
        return {
            "clients": clients,
            "hubs": hubs,
            "areas": areas,
            "uptime": uptime,
            "uptime_fmt": f"{uptime//86400}д {uptime%86400//3600}ч {uptime%3600//60}м",
            "db_size": db_size,
            "db_size_fmt": f"{db_size/1024:.0f} KB" if db_size < 1048576 else f"{db_size/1048576:.1f} MB",
            "messages_per_hour": msg_h,
        }

    @app.get("/api/admin/perf/history")
    async def api_perf_history(request: Request, hours: int = 24):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            rows = conn.execute(
                """SELECT STRFTIME('%Y-%m-%d %H:00', event_time) AS bucket,
                          COUNT(*) AS cnt FROM area_events
                   WHERE event_time >= datetime('now',?)
                   AND event_subtype IN (SELECT type_id FROM area_event_types WHERE type_name IN ('chat.ic','chat.ooc'))
                   GROUP BY bucket ORDER BY bucket""",
                (f"-{hours} hours",),
            ).fetchall()
        return {"buckets": [{"time": r["bucket"], "count": r["cnt"]} for r in rows]}

    # ─── Guild / Group manager page ───────────────────────────
    @app.get("/admin/groups")
    async def groups_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if not _role_at_least(acc, "moderator"):
            return render("login.html", request=request, error="Доступ запрещён")
        return render("admin_groups.html", request=request)

    @app.get("/api/admin/groups")
    async def api_groups_list(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            rows = conn.execute(
                """SELECT g.*, (SELECT COUNT(*) FROM player_group_members WHERE group_id=g.id) AS member_count
                   FROM player_groups g ORDER BY g.name"""
            ).fetchall()
        return {"groups": [dict(r) for r in rows]}

    @app.post("/api/admin/groups")
    async def api_groups_create(request: Request):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        name = body.get("name", "").strip()
        if not name:
            return {"ok": False, "error": "Введите название"}
        with _wp_db_conn as conn:
            try:
                conn.execute("INSERT INTO player_groups(name,description,leader_ipid) VALUES (?,?,?)",
                             (name, body.get("description", ""), body.get("leader_ipid")))
            except Exception as e:
                return {"ok": False, "error": str(e)}
        return {"ok": True}

    @app.post("/api/admin/groups/{group_id}")
    async def api_groups_update(request: Request, group_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        sets = []; params = []
        for k in ("name","description","leader_ipid"):
            if k in body:
                sets.append(f"{k}=?"); params.append(body[k])
        if not sets:
            return {"ok": False, "error": "No fields"}
        params.append(group_id)
        with _wp_db_conn as conn:
            conn.execute(f"UPDATE player_groups SET {','.join(sets)} WHERE id=?", params)
        return {"ok": True}

    @app.post("/api/admin/groups/{group_id}/delete")
    async def api_groups_delete(request: Request, group_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        with _wp_db_conn as conn:
            conn.execute("DELETE FROM player_group_members WHERE group_id=?", (group_id,))
            conn.execute("DELETE FROM player_groups WHERE id=?", (group_id,))
        return {"ok": True}

    @app.get("/api/admin/groups/{group_id}/members")
    async def api_groups_members(request: Request, group_id: int):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            rows = conn.execute(
                "SELECT pm.*, COALESCE(ps.ic_messages,0) AS msg_count FROM player_group_members pm LEFT JOIN player_stats ps ON pm.ipid=ps.ipid WHERE pm.group_id=? ORDER BY pm.role, pm.ipid",
                (group_id,),
            ).fetchall()
        return {"members": [dict(r) for r in rows]}

    @app.post("/api/admin/groups/{group_id}/members")
    async def api_groups_members_add(request: Request, group_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        ipid = int(body["ipid"])
        role = body.get("role", "member")
        with _wp_db_conn as conn:
            try:
                conn.execute("INSERT INTO player_group_members(group_id,ipid,role) VALUES (?,?,?)", (group_id, ipid, role))
            except Exception:
                return {"ok": False, "error": "Уже в группе"}
        return {"ok": True}

    @app.post("/api/admin/groups/{group_id}/members/{member_id}/delete")
    async def api_groups_members_delete(request: Request, group_id: int, member_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        with _wp_db_conn as conn:
            conn.execute("DELETE FROM player_group_members WHERE id=? AND group_id=?", (member_id, group_id))
        return {"ok": True}

    # ─── Report generator page ─────────────────────────────────
    @app.get("/admin/reports")
    async def reports_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if not _role_at_least(acc, "ga"):
            return render("login.html", request=request, error="Доступ запрещён")
        return render("admin_reports.html", request=request)

    @app.post("/api/admin/reports/generate")
    async def api_reports_generate(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        days = int(body.get("days", 7))
        with _wp_db_conn as conn:
            # DAU (unique players per day)
            dau = conn.execute(
                """SELECT DATE(event_time) AS day, COUNT(DISTINCT ipid) AS cnt
                   FROM area_events WHERE event_time >= datetime('now',?)
                   AND event_subtype IN (SELECT type_id FROM area_event_types WHERE type_name IN ('chat.ic','chat.ooc'))
                   GROUP BY day ORDER BY day""",
                (f"-{days} days",),
            ).fetchall()
            # Message counts
            total_msg = conn.execute(
                "SELECT COUNT(*) FROM area_events WHERE event_time>=datetime('now',?) AND event_subtype IN (SELECT type_id FROM area_event_types WHERE type_name IN ('chat.ic','chat.ooc'))",
                (f"-{days} days",),
            ).fetchone()[0]
            # New players (distinct ipids first seen)
            new_players = conn.execute(
                "SELECT COUNT(DISTINCT ipid) FROM area_events WHERE event_time>=datetime('now',?)",
                (f"-{days} days",),
            ).fetchone()[0]
            # Bans in period
            bans = conn.execute(
                "SELECT COUNT(*) FROM bans WHERE ban_date>=datetime('now',?)",
                (f"-{days} days",),
            ).fetchone()[0]
            # Achievements granted
            achievements = conn.execute(
                "SELECT COUNT(*) FROM player_achievements WHERE unlocked_at>=datetime('now',?)",
                (f"-{days} days",),
            ).fetchone()[0]
            # Top players
            top = conn.execute(
                """SELECT ipid, COUNT(*) AS cnt FROM area_events
                   WHERE event_time>=datetime('now',?) AND event_subtype IN (SELECT type_id FROM area_event_types WHERE type_name IN ('chat.ic','chat.ooc'))
                   GROUP BY ipid ORDER BY cnt DESC LIMIT 10""",
                (f"-{days} days",),
            ).fetchall()
        return {
            "days": days,
            "dau": [{"day": r["day"], "count": r["cnt"]} for r in dau],
            "total_messages": total_msg,
            "new_players": new_players,
            "bans": bans,
            "achievements_granted": achievements,
            "avg_dau": round(sum(r["cnt"] for r in dau) / len(dau), 1) if dau else 0,
            "top_players": [{"ipid": r["ipid"], "count": r["cnt"]} for r in top],
            "generated_at": datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S"),
        }

    # ─── Quests page ──────────────────────────────────────────
    @app.get("/quests")
    async def quests_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if not _role_at_least(acc, "ga"):
            return render("login.html", request=request, error="Доступ запрещён")
        return render("quests.html", request=request)

    @app.get("/api/quests")
    async def api_quests_list(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            rows = conn.execute("SELECT * FROM quests ORDER BY created_at DESC").fetchall()
        return {"quests": [dict(r) for r in rows]}

    @app.post("/api/quests")
    async def api_quests_create(request: Request):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        with _wp_db_conn as conn:
            conn.execute(
                "INSERT INTO quests(name,description,trigger_type,trigger_config,stages,rewards,enabled) VALUES (?,?,?,?,?,?,?)",
                (body["name"], body.get("description",""), body.get("trigger_type","manual"),
                 json.dumps(body.get("trigger_config",{})), json.dumps(body.get("stages",[])),
                 json.dumps(body.get("rewards",{})), int(body.get("enabled",1))),
            )
        return {"ok": True}

    @app.post("/api/quests/{quest_id}")
    async def api_quests_update(request: Request, quest_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        sets = []; params = []
        for k in ("name","description","trigger_type","enabled"):
            if k in body:
                sets.append(f"{k}=?"); params.append(body[k])
        for k in ("trigger_config","stages","rewards"):
            if k in body:
                sets.append(f"{k}=?"); params.append(json.dumps(body[k]))
        if not sets:
            return {"ok": False, "error": "No fields"}
        params.append(quest_id)
        with _wp_db_conn as conn:
            conn.execute(f"UPDATE quests SET {','.join(sets)} WHERE id=?", params)
        return {"ok": True}

    @app.post("/api/quests/{quest_id}/delete")
    async def api_quests_delete(request: Request, quest_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        with _wp_db_conn as conn:
            conn.execute("DELETE FROM quests WHERE id=?", (quest_id,))
        return {"ok": True}

    # ─── Bulletin page ────────────────────────────────────────
    @app.get("/bulletin")
    async def bulletin_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if not _role_at_least(acc, "ga"):
            return render("login.html", request=request, error="Доступ запрещён")
        return render("bulletin.html", request=request)

    @app.get("/api/bulletin")
    async def api_bulletin_list(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            rows = conn.execute(
                "SELECT * FROM bulletin_posts ORDER BY pinned DESC, created_at DESC"
            ).fetchall()
        return {"posts": [dict(r) for r in rows]}

    @app.post("/api/bulletin")
    async def api_bulletin_create(request: Request):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        with _wp_db_conn as conn:
            conn.execute(
                "INSERT INTO bulletin_posts(title,content,expires_at,pinned) VALUES (?,?,?,?)",
                (body["title"], body.get("content",""), body.get("expires_at"), int(body.get("pinned",0))),
            )
        return {"ok": True}

    @app.post("/api/bulletin/{post_id}/delete")
    async def api_bulletin_delete(request: Request, post_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        with _wp_db_conn as conn:
            conn.execute("DELETE FROM bulletin_posts WHERE id=?", (post_id,))
        return {"ok": True}

    # ─── Word filter page ─────────────────────────────────────
    @app.get("/wordfilter")
    async def wordfilter_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if not _role_at_least(acc, "ga"):
            return render("login.html", request=request, error="Доступ запрещён")
        return render("wordfilter.html", request=request)

    @app.get("/api/wordfilter")
    async def api_wordfilter_list(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            rows = conn.execute("SELECT * FROM word_filters ORDER BY created_at DESC").fetchall()
        return {"filters": [dict(r) for r in rows]}

    @app.post("/api/wordfilter")
    async def api_wordfilter_create(request: Request):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        with _wp_db_conn as conn:
            conn.execute(
                "INSERT INTO word_filters(pattern,replacement,hub_name,area_name,enabled,warn_on_match) VALUES (?,?,?,?,?,?)",
                (body["pattern"], body.get("replacement","***"), body.get("hub_name",""),
                 body.get("area_name",""), int(body.get("enabled",1)), int(body.get("warn_on_match",0))),
            )
        return {"ok": True}

    @app.post("/api/wordfilter/{filter_id}")
    async def api_wordfilter_update(request: Request, filter_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        sets = []; params = []
        for k in ("pattern","replacement","hub_name","area_name","enabled","warn_on_match"):
            if k in body:
                sets.append(f"{k}=?"); params.append(body[k])
        if not sets:
            return {"ok": False, "error": "No fields"}
        params.append(filter_id)
        with _wp_db_conn as conn:
            conn.execute(f"UPDATE word_filters SET {','.join(sets)} WHERE id=?", params)
        return {"ok": True}

    @app.post("/api/wordfilter/{filter_id}/delete")
    async def api_wordfilter_delete(request: Request, filter_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        with _wp_db_conn as conn:
            conn.execute("DELETE FROM word_filters WHERE id=?", (filter_id,))
        return {"ok": True}

    # ─── Shop page ────────────────────────────────────────────
    @app.get("/shop")
    async def shop_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if not _role_at_least(acc, "ga"):
            return render("login.html", request=request, error="Доступ запрещён")
        return render("shop.html", request=request)

    @app.get("/api/shop/items")
    async def api_shop_items(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            rows = conn.execute("SELECT * FROM shop_items ORDER BY created_at DESC").fetchall()
        return {"items": [dict(r) for r in rows]}

    @app.post("/api/shop/items")
    async def api_shop_items_create(request: Request):
        if not _role_at_least(_account(request), "admin"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        with _wp_db_conn as conn:
            conn.execute(
                "INSERT INTO shop_items(name,description,price,item_type,item_data,stock,enabled) VALUES (?,?,?,?,?,?,?)",
                (body["name"], body.get("description",""), int(body.get("price",0)),
                 body.get("item_type","item"), json.dumps(body.get("item_data",{})),
                 int(body.get("stock",-1)), int(body.get("enabled",1))),
            )
        return {"ok": True}

    @app.post("/api/shop/items/{item_id}")
    async def api_shop_items_update(request: Request, item_id: int):
        if not _role_at_least(_account(request), "admin"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        sets = []; params = []
        for k in ("name","description","price","item_type","stock","enabled"):
            if k in body:
                sets.append(f"{k}=?"); params.append(body[k])
        if "item_data" in body:
            sets.append("item_data=?"); params.append(json.dumps(body["item_data"]))
        if not sets:
            return {"ok": False, "error": "No fields"}
        params.append(item_id)
        with _wp_db_conn as conn:
            conn.execute(f"UPDATE shop_items SET {','.join(sets)} WHERE id=?", params)
        return {"ok": True}

    @app.post("/api/shop/items/{item_id}/delete")
    async def api_shop_items_delete(request: Request, item_id: int):
        if not _role_at_least(_account(request), "admin"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        with _wp_db_conn as conn:
            conn.execute("DELETE FROM shop_items WHERE id=?", (item_id,))
        return {"ok": True}

    @app.get("/api/shop/purchases")
    async def api_shop_purchases(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            rows = conn.execute(
                "SELECT p.*, s.name AS item_name FROM shop_purchases p LEFT JOIN shop_items s ON p.item_id=s.id ORDER BY p.purchased_at DESC LIMIT 200"
            ).fetchall()
        return {"purchases": [dict(r) for r in rows]}

    # ─── Player notes page ────────────────────────────────────
    @app.get("/playernotes")
    async def playernotes_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        if not _role_at_least(_account(request), "moderator"):
            return RedirectResponse(url="/")
        return render("playernotes.html", request=request)

    @app.get("/api/playernotes")
    async def api_playernotes_list(request: Request, target_ipid: int = None):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        with _wp_db_conn as conn:
            if target_ipid:
                rows = conn.execute("SELECT * FROM player_notes WHERE target_ipid=? ORDER BY created_at DESC", (target_ipid,)).fetchall()
            else:
                rows = conn.execute("SELECT * FROM player_notes ORDER BY created_at DESC LIMIT 200").fetchall()
        return {"notes": [dict(r) for r in rows]}

    @app.post("/api/playernotes")
    async def api_playernotes_create(request: Request):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        acc = _account(request)
        with _wp_db_conn as conn:
            conn.execute(
                "INSERT INTO player_notes(target_ipid,note,created_by_ipid) VALUES (?,?,?)",
                (int(body["target_ipid"]), body["note"], acc.get("ipid")),
            )
        return {"ok": True}

    @app.post("/api/playernotes/{note_id}/delete")
    async def api_playernotes_delete(request: Request, note_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        with _wp_db_conn as conn:
            conn.execute("DELETE FROM player_notes WHERE id=?", (note_id,))
        return {"ok": True}

    # ─── IPID History page ────────────────────────────────────
    @app.get("/ipid_history")
    async def ipid_history_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if not _role_at_least(acc, "ga"):
            return render("login.html", request=request, error="Доступ запрещён")
        return render("ipid_history.html", request=request)

    @app.post("/api/ipid_history")
    async def api_ipid_history(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        ipid = int(body.get("ipid", 0))
        if not ipid:
            return {"ok": False, "error": "Введите IPID"}
        result = {"ipid": ipid}
        with _wp_db_conn as conn:
            # Accounts
            try:
                accs = conn.execute("SELECT id,login,role FROM accounts WHERE ipid=?", (ipid,)).fetchall()
                result["accounts"] = [dict(a) for a in accs]
            except Exception:
                result["accounts"] = []
            # Characters
            chars = conn.execute(
                "SELECT char_name, area_name, hub_name, MAX(event_time) AS last_seen FROM area_events WHERE ipid=? GROUP BY char_name ORDER BY last_seen DESC LIMIT 50",
                (ipid,),
            ).fetchall()
            result["characters"] = [dict(c) for c in chars]
            # First / last seen
            times = conn.execute(
                "SELECT MIN(event_time) AS first_seen, MAX(event_time) AS last_seen FROM area_events WHERE ipid=?",
                (ipid,),
            ).fetchone()
            result["first_seen"] = times["first_seen"] if times else None
            result["last_seen"] = times["last_seen"] if times else None
            # Areas visited
            area_count = conn.execute(
                "SELECT COUNT(DISTINCT area_name) FROM area_events WHERE ipid=?", (ipid,)
            ).fetchone()[0]
            result["areas_visited"] = area_count or 0
            # Stats
            stats = conn.execute("SELECT * FROM player_stats WHERE ipid=?", (ipid,)).fetchone()
            result["stats"] = dict(stats) if stats else None
        return result

    # ─── Auto-mod page ────────────────────────────────────────
    @app.get("/automod")
    async def automod_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        if not _role_at_least(_account(request), "moderator"):
            return RedirectResponse(url="/")
        return render("automod.html", request=request)

    @app.get("/api/automod")
    async def api_automod_list(request: Request):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        with _wp_db_conn as conn:
            rows = conn.execute("SELECT * FROM automod_configs ORDER BY created_at DESC").fetchall()
        return {"configs": [dict(r) for r in rows]}

    @app.post("/api/automod")
    async def api_automod_create(request: Request):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        with _wp_db_conn as conn:
            conn.execute(
                "INSERT INTO automod_configs(hub_name,area_name,enabled,flood_msg_limit,flood_window_secs,caps_percent,caps_min_len,repeat_count,repeat_window_secs,action,check_word_filter) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                (body.get("hub_name",""), body.get("area_name",""), int(body.get("enabled",1)),
                 int(body.get("flood_msg_limit",5)), int(body.get("flood_window_secs",3)),
                 int(body.get("caps_percent",70)), int(body.get("caps_min_len",5)),
                 int(body.get("repeat_count",3)), int(body.get("repeat_window_secs",10)),
                 body.get("action","warn"), int(body.get("check_word_filter",1))),
            )
        return {"ok": True}

    @app.post("/api/automod/{config_id}")
    async def api_automod_update(request: Request, config_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        sets = []; params = []
        for k in ("hub_name","area_name","enabled","flood_msg_limit","flood_window_secs","caps_percent","caps_min_len","repeat_count","repeat_window_secs","action","check_word_filter"):
            if k in body:
                sets.append(f"{k}=?"); params.append(body[k])
        if not sets:
            return {"ok": False, "error": "No fields"}
        params.append(config_id)
        with _wp_db_conn as conn:
            conn.execute(f"UPDATE automod_configs SET {','.join(sets)} WHERE id=?", params)
        return {"ok": True}

    @app.post("/api/automod/{config_id}/delete")
    async def api_automod_delete(request: Request, config_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        with _wp_db_conn as conn:
            conn.execute("DELETE FROM automod_configs WHERE id=?", (config_id,))
        return {"ok": True}

    # ─── Polls page ───────────────────────────────────────────
    @app.get("/polls")
    async def polls_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        acc = _account(request)
        if not _role_at_least(acc, "ga"):
            return render("login.html", request=request, error="Доступ запрещён")
        return render("polls.html", request=request)

    @app.get("/api/polls")
    async def api_polls_list(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        with _wp_db_conn as conn:
            rows = conn.execute("SELECT * FROM polls ORDER BY created_at DESC").fetchall()
            polls_out = []
            for row in rows:
                p = dict(row)
                p["vote_count"] = conn.execute("SELECT COUNT(*) FROM poll_votes WHERE poll_id=?", (p["id"],)).fetchone()[0]
                p["votes_yes"] = conn.execute("SELECT COUNT(*) FROM poll_votes WHERE poll_id=? AND vote='yes'", (p["id"],)).fetchone()[0]
                p["votes_no"] = p["vote_count"] - p["votes_yes"]
                polls_out.append(p)
        return {"polls": polls_out}

    @app.post("/api/polls")
    async def api_polls_create(request: Request):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        with _wp_db_conn as conn:
            conn.execute(
                "INSERT INTO polls(question,expires_at) VALUES (?,?)",
                (body["question"], body.get("expires_at")),
            )
        return {"ok": True}

    @app.post("/api/polls/{poll_id}/vote")
    async def api_polls_vote(request: Request, poll_id: int):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        body = await request.json()
        acc = _account(request)
        ipid = acc.get("ipid")
        if not ipid:
            return {"ok": False, "error": "Нет IPID"}
        with _wp_db_conn as conn:
            try:
                conn.execute("INSERT INTO poll_votes(poll_id,ipid,vote) VALUES (?,?,?)", (poll_id, ipid, body.get("vote","yes")))
            except Exception:
                return {"ok": False, "error": "Уже проголосовали"}
        return {"ok": True}

    @app.post("/api/polls/{poll_id}/close")
    async def api_polls_close(request: Request, poll_id: int):
        if not _role_at_least(_account(request), "moderator"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        with _wp_db_conn as conn:
            conn.execute("UPDATE polls SET closed=1 WHERE id=?", (poll_id,))
        return {"ok": True}

    # ─── Achievement editor page ──────────────────────────────
    @app.get("/achievement_editor")
    async def achievement_editor_page(request: Request):
        if not _auth(request):
            return RedirectResponse(url="/login")
        if not _role_at_least(_account(request), "admin"):
            return RedirectResponse(url="/")
        return render("achievement_editor.html", request=request)

    @app.get("/api/achievement_editor/defs")
    async def api_achievement_editor_defs(request: Request):
        if not _auth(request):
            return JSONResponse({"error": "Unauthorized"}, status_code=401)
        from server.achievements import get_all_defs
        defs = get_all_defs()
        return {"definitions": [dict({"id": k, **v}) for k,v in defs.items()]}

    @app.post("/api/achievement_editor/defs")
    async def api_achievement_editor_create(request: Request):
        if not _role_at_least(_account(request), "admin"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        aid = body.get("id","").strip()
        if not aid:
            return {"ok": False, "error": "Введите ID"}
        with _wp_db_conn as conn:
            try:
                conn.execute(
                    "INSERT INTO achievement_defs(id,name,description,icon,category,criteria_type,criteria_value) VALUES (?,?,?,?,?,?,?)",
                    (aid, body["name"], body.get("description",""), body.get("icon",""),
                     body.get("category","other"), body.get("criteria_type","ic_messages"),
                     int(body.get("criteria_value",1))),
                )
            except Exception as e:
                return {"ok": False, "error": str(e)}
        return {"ok": True}

    @app.post("/api/achievement_editor/defs/{achievement_id}")
    async def api_achievement_editor_update(request: Request, achievement_id: str):
        if not _role_at_least(_account(request), "admin"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        body = await request.json()
        sets = []; params = []
        for k in ("name","description","icon","category","criteria_type","criteria_value"):
            if k in body:
                sets.append(f"{k}=?"); params.append(body[k])
        if not sets:
            return {"ok": False, "error": "No fields"}
        params.append(achievement_id)
        with _wp_db_conn as conn:
            conn.execute(f"UPDATE achievement_defs SET {','.join(sets)} WHERE id=?", params)
        return {"ok": True}

    @app.post("/api/achievement_editor/defs/{achievement_id}/delete")
    async def api_achievement_editor_delete(request: Request, achievement_id: str):
        if not _role_at_least(_account(request), "admin"):
            return JSONResponse({"error": "Forbidden"}, status_code=403)
        with _wp_db_conn as conn:
            conn.execute("DELETE FROM achievement_defs WHERE id=?", (achievement_id,))
            conn.execute("DELETE FROM player_achievements WHERE achievement_id=?", (achievement_id,))
        return {"ok": True}

    @app.websocket("/ws")
    async def ws_endpoint(ws: WebSocket):
        if ws.cookies.get("token") != _config.get("password"):
            await ws.close(code=4001)
            return
        await ws.accept()
        _ws_clients.add(ws)
        try:
            while True:
                await ws.receive_text()
        except WebSocketDisconnect:
            pass
        finally:
            _ws_clients.discard(ws)

    @app.websocket("/ws/player")
    async def ws_player(ws: WebSocket):
        acc = _account_from_ws(ws)
        player_code = _player_code_session_from_ws(ws)
        if not acc and not player_code:
            await ws.close(code=4001)
            return
        if acc and not _role_at_least(acc, "gm") and not player_code:
            await ws.close(code=4003, reason="Доступ запрещён")
            return
        await ws.accept()
        srv = _server_ref
        if not srv:
            await ws.send_json({"type": "error", "msg": "Сервер не загружен"})
            await ws.close()
            return
        from server.webpanel.player_client import PlayerManager, PlayerTransport
        transport = PlayerTransport(ws, asyncio.get_running_loop())
        username = acc.get("username", "Player") if acc else "Player"
        manager = PlayerManager(srv, transport, username)
        try:
            manager.setup()
            await ws.send_json({"type": "ready", "msg": "Подключено к серверу"})
            while True:
                data = await ws.receive_json()
                manager.handle_command(data)
        except WebSocketDisconnect:
            pass
        except Exception as e:
            logger.warning("WS player error: %s", e)
        finally:
            manager.teardown()

    return app


async def run_webpanel(server):
    cfg = server.config.get("webpanel", {})
    if not cfg.get("enabled"):
        return

    import uvicorn, threading

    _app = make_app(server, cfg)

    def _run():
        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)
        try:
            config = uvicorn.Config(_app, host=cfg.get("host", "127.0.0.1"),
                                    port=int(cfg.get("port", 8080)), log_level="warning")
            sv = uvicorn.Server(config)
            logger.info("Web panel starting on %s:%s", cfg.get("host"), cfg.get("port"))
            sv.run()
        except Exception as exc:
            logger.error("Web panel error: %s", exc)
        finally:
            loop.close()

    t = threading.Thread(target=_run, daemon=True)
    t.start()
