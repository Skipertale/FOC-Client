import uvicorn
from webpanel import make_app

# Подставь свой объект сервера и конфиг
server = ...

app = make_app(server, {
    "host": "0.0.0.0",
    "port": 8080,
    "enabled": True,
})

if __name__ == "__main__":
    uvicorn.run(
        app,
        host="0.0.0.0",
        port=8080,
        log_level="info"
    )