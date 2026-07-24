# AI Motion Radar Service

Python FastAPI backend for the CIA dashboard (`frontend/web/cia-radar.php`).

## Install (Windows PowerShell)

```powershell
cd c:\xampp\htdocs\Getways-App\radar-service
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
```

## Run Demo Mode

Set in project `.env`:

```env
RADAR_MODE=mock
RADAR_SERVICE_URL=http://127.0.0.1:8765
```

Start the service:

```powershell
cd c:\xampp\htdocs\Getways-App\radar-service
.\.venv\Scripts\Activate.ps1
uvicorn main:app --host 127.0.0.1 --port 8765 --reload
```

Open `http://localhost/Getways-App/frontend/web/cia-radar.php`, click **Enable Alarm Sound**, connect camera, arm the system.

## Connect Real Serial Radar (ESP32 / Arduino)

Example serial JSON per line:

```json
{"event":"motion","distance_m":2.8,"angle_deg":35,"speed_mps":0.7,"energy":64,"timestamp":"2026-07-24T10:42:18Z"}
```

`.env`:

```env
RADAR_MODE=hardware
RADAR_SERIAL_PORT=COM3
RADAR_BAUD_RATE=115200
RADAR_SENSOR_PROFILE=MR24HPC1
```

Arduino/ESP32 sketch outline:

```cpp
void loop() {
  if (motionDetected) {
    Serial.println("{\"event\":\"motion\",\"distance_m\":2.8,\"angle_deg\":35,\"speed_mps\":0.7,\"energy\":64}");
  }
  delay(200);
}
```

## Tests

```powershell
cd c:\xampp\htdocs\Getways-App\radar-service
.\.venv\Scripts\Activate.ps1
pytest -q
```

## API

- `GET /api/radar/status`
- `GET/PUT /api/radar/settings`
- `POST /api/radar/arm` / `disarm` / `stop-all`
- `GET /api/radar/events`
- `WS /ws/radar/events`

Media stored under `radar-service/data/events/`.
