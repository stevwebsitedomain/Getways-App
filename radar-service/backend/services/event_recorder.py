from __future__ import annotations

from backend.services.event_service import event_service, re_filename_safe


class EventRecorder:
    def store_snapshot(self, filename: str, content: bytes) -> str:
        return event_service.save_media(re_filename_safe(filename), content)

    def store_video(self, filename: str, content: bytes) -> str:
        return event_service.save_media(re_filename_safe(filename), content)


event_recorder = EventRecorder()
