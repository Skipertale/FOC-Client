import sys, os, time, argparse

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", ".."))

import oyaml as yaml
from pathlib import Path

CONFIG_PATH = Path(__file__).resolve().parent.parent.parent / "config" / "config.yaml"
with open(CONFIG_PATH) as f:
    full_cfg = yaml.safe_load(f)

web_cfg = full_cfg.get("webpanel", {})
if not web_cfg.get("enabled"):
    print("Webpanel not enabled in config")
    sys.exit(1)

parser = argparse.ArgumentParser()
parser.add_argument("--host", default=None)
parser.add_argument("--port", default=None)
args, _ = parser.parse_known_args()

host = args.host or web_cfg.get("host", "0.0.0.0")
port = int(args.port or web_cfg.get("port", 8080))


class MockServer:
    def __init__(self, cfg):
        self.config = cfg
        self.player_count = 0
        self.start_time = time.time()
        self.client_manager = type("cm", (), {"clients": []})()
        self.hub_manager = type("hm", (), {
            "hubs": [],
            "get_hub_by_id": lambda self, hid: None,
            "default_area": lambda self: None,
        })()

    def send_all_cmd_pred(self, *a, **kw):
        pass


server = MockServer(full_cfg)

from server.webpanel import make_app

app = make_app(server, web_cfg)

# Force full sync on startup so CRM data is ready immediately
from server.webpanel import _crm_repo
if _crm_repo:
    try:
        print("CRM: initial full sync...")
        _crm_repo.sync_player_cache(force_full=True)
        print("CRM: sync complete")
    except Exception as e:
        print(f"CRM: sync error: {e}")

if __name__ == "__main__":
    import uvicorn

    print(f"Starting webpanel on {host}:{port}")
    uvicorn.run(app, host=host, port=port, log_level="info")
