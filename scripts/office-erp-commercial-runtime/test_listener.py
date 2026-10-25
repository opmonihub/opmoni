import os
from pathlib import Path
import socket
import subprocess
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
from listener import ensure_port_available, verify_listener_owner


class ListenerTest(unittest.TestCase):
    def test_occupied_loopback_port_is_rejected(self):
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
            listener.bind(('127.0.0.1', 0))
            listener.listen()
            port = listener.getsockname()[1]
            with self.assertRaisesRegex(RuntimeError, 'occupied'):
                ensure_port_available('127.0.0.1', port)

    def test_listener_must_belong_to_recorded_process(self):
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
            listener.bind(('127.0.0.1', 0))
            listener.listen()
            port = listener.getsockname()[1]
            verify_listener_owner(os.getpid(), '127.0.0.1', port)
            with subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(2)']) as other_process:
                with self.assertRaisesRegex(RuntimeError, 'recorded process'):
                    verify_listener_owner(other_process.pid, '127.0.0.1', port)

    def test_missing_listener_is_rejected(self):
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
            with self.assertRaisesRegex(RuntimeError, 'no listener'):
                verify_listener_owner(os.getpid(), '127.0.0.1', port)

    def test_other_process_listener_is_rejected_without_stopping_it(self):
        code = ('import socket; '
                's=socket.socket(); s.bind(("127.0.0.1", 0)); s.listen(); '
                'print(s.getsockname()[1], flush=True); input()')
        with subprocess.Popen([sys.executable, '-u', '-c', code],
                              stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True) as other_process:
            port = int(other_process.stdout.readline())
            with self.assertRaisesRegex(RuntimeError, 'occupied'):
                ensure_port_available('127.0.0.1', port)
            with self.assertRaisesRegex(RuntimeError, 'recorded process'):
                verify_listener_owner(os.getpid(), '127.0.0.1', port)
            self.assertIsNone(other_process.poll())
            other_process.stdin.write('\n')
            other_process.stdin.flush()


if __name__ == '__main__':
    unittest.main()
