---
name: nuvora-edge
description: Develop and maintain the Nuvora Edge Agent that connects Nuvora Cloud with local parking hardware.
---
# Nuvora Edge

Nuvora Edge is a local hardware integration agent, not a business-domain microservice.

Responsibilities:
- connect to local cameras
- consume RTSP/ONVIF/HTTP camera interfaces
- integrate barriers, RFID, QR, printers, sensors, and similar devices
- perform or invoke local LPR processing
- persist local events during Internet outages
- synchronize events with Nuvora Cloud
- report device health

Recommended technology:
- Python
- FastAPI for local management/API needs
- SQLite for local persistence
- OpenCV/FFmpeg where required
- HTTPS/WebSocket for Cloud communication

Do not put tariff, reservation, membership, or other authoritative business rules in Edge.

Use hardware adapters:
CameraAdapter
GateAdapter
ReaderAdapter
PlateRecognitionProvider

Never hard-code one manufacturer into the core.

Cloud communication must be authenticated and encrypted.

Events must be idempotent.

Support store-and-forward synchronization after connectivity loss.

Never log credentials, tokens, or secrets.
