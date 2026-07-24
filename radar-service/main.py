from __future__ import annotations

import asyncio

from fastapi import FastAPI, WebSocket, WebSocketDisconnect
from fastapi.middleware.cors import CORSMiddleware

from backend.api.radar_routes import _status, broadcast_event, router as radar_router, _ws_clients
from backend.db import init_db
from backend.services.radar_serial_service import radar_serial_service

app = FastAPI(title="AI Motion Radar Service", version="1.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(radar_router)


@app.websocket("/ws/radar/events")
async def ws_radar_events(websocket: WebSocket) -> None:
    await websocket.accept()
    _ws_clients.add(websocket)
    try:
        await websocket.send_json({"type": "status", "payload": _status().model_dump()})
        while True:
            for raw in radar_serial_service.poll_events():
                await broadcast_event({"type": "radar_hardware", "payload": raw})
            try:
                await asyncio.wait_for(websocket.receive_text(), timeout=1.0)
            except asyncio.TimeoutError:
                continue
    except WebSocketDisconnect:
        pass
    finally:
        _ws_clients.discard(websocket)


@app.on_event("startup")
def on_startup() -> None:
    init_db()
    radar_serial_service.start()


@app.on_event("shutdown")
def on_shutdown() -> None:
    radar_serial_service.stop()


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}
