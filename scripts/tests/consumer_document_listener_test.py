#!/usr/bin/env python3
"""Offline listener admission checks; uses only an ephemeral local test port."""

from pathlib import Path
import runpy
import socket
import threading
import time
import tempfile
from unittest import TestCase, main, mock


SOURCE = Path(__file__).resolve().parents[1] / "consumer-document-article"


class ListenerAdmissionTest(TestCase):
    def setUp(self):
        self.consumer = runpy.run_path(str(SOURCE))

    def listener(self):
        listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        listener.bind(("127.0.0.1", 0))
        self.addCleanup(listener.close)
        return listener, f"http://127.0.0.1:{listener.getsockname()[1]}"

    def test_already_ready_listener(self):
        listener, endpoint = self.listener()
        listener.listen()
        self.consumer["wait_for_listener"](endpoint, seconds=0.2)

    def test_delayed_listener(self):
        listener, endpoint = self.listener()
        ready = threading.Event()

        def start():
            time.sleep(0.1)
            listener.listen()
            ready.set()

        worker = threading.Thread(target=start)
        worker.start()
        self.addCleanup(worker.join)
        self.consumer["wait_for_listener"](endpoint, seconds=1)
        self.assertTrue(ready.is_set())

    def test_refused_listener_times_out_without_journal(self):
        _, endpoint = self.listener()
        with self.assertRaisesRegex(self.consumer["Rejected"], "ENDPOINT_LISTENER_NOT_READY"):
            self.consumer["wait_for_listener"](endpoint, seconds=0.1)
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / "not-started.jsonl"
            with mock.patch.dict(self.consumer["execute"].__globals__,
                                 verify_grant=lambda *_: None,
                                 wait_for_listener=lambda *_: (_ for _ in ()).throw(self.consumer["Rejected"]("ENDPOINT_LISTENER_NOT_READY"))):
                with self.assertRaisesRegex(self.consumer["Rejected"], "ENDPOINT_LISTENER_NOT_READY"):
                    self.consumer["execute"]({"endpoint": endpoint, "output": str(output)}, "unused")
            self.assertFalse(output.exists())


if __name__ == "__main__":
    main()
