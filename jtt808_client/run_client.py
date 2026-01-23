
import sys
import os
import time
import threading
import socket
import logging

# Mock QuecPython modules
class MockUOS:
    def listdir(self, path):
        return []
    def remove(self, path):
        pass
    def rename(self, src, dst):
        pass

class MockUSYS:
    def print_exception(self, e):
        print(f"Exception: {e}")
        import traceback
        traceback.print_exc()

    import traceback
    traceback.print_exc()

sys.print_exception = MockUSYS().print_exception

class MockUTime:
    def sleep(self, s):
        time.sleep(s)
    def sleep_ms(self, ms):
        time.sleep(ms / 1000.0)
    def localtime(self):
        return time.localtime()
    def ticks_ms(self):
        return int(time.time() * 1000)
    def ticks_diff(self, a, b):
        return a - b

class MockTimer:
    def __init__(self, timer_id=0):
        self._timer_id = timer_id
        self._timer = None

    def start(self, period, mode, callback):
        def _run():
            while True:
                time.sleep(period / 1000.0)
                callback(None)
                if mode == 0: # ONE_SHOT (assuming 0 is one shot based on usage implies?) 
                              # Actually QuecPython doc says: osTimer.ONE_SHOT = 0, osTimer.PERIODIC = 1
                    break
        self._timer = threading.Thread(target=_run)
        self._timer.daemon = True
        self._timer.start()

    def stop(self):
        # Difficult to stop a thread in Python without cooperation, but for mocks this is often skipped or 
        # implemented with a flag if strictly related.
        pass

class MockOS: # QuecPython 'osTimer' is a module usually called 'osTimer'
    pass

class MockThread:
    def start_new_thread(self, func, args):
        t = threading.Thread(target=func, args=args)
        t.daemon = True
        t.start()
        return t.ident
    def get_ident(self):
        return threading.get_ident()
    def allocate_lock(self):
        return threading.Lock()
    def stack_size(self, size=0):
        pass
    def threadIsRunning(self, tid):
        if tid is None: return False
        for t in threading.enumerate():
            if t.ident == tid:
                return True
        return False
    def stop_thread(self, tid):
        pass

class MockSim:
    def getIccid(self):
        return "89860000000000000000"

class MockModem:
    def getDevImei(self):
        return "860000000000000"
    def getDevFwVersion(self):
        return "MockVer1.0"

# Inject Mocks
sys.modules['uos'] = MockUOS()
sys.modules['usys'] = MockUSYS()
sys.modules['utime'] = MockUTime()
sys.modules['osTimer'] = MockTimer # The import in jtt808.py is `import osTimer`, and uses `osTimer()` as a class constructor?
                                   # Looking at code: `from usr.logging import getLogger` ... `import osTimer`
                                   # `self.__subpkg_timer[header["message_id"]] = osTimer()` -> It calls the module as a callable? 
                                   # Or `osTimer` is a class in that module?
                                   # Let's check jtt808.py lines 249: `self.__subpkg_timer[header["message_id"]] = osTimer()`
                                   # This suggests osTimer IS the class or function.
                                   # So we inject our MockTimer CLASS as the module `osTimer`? 
                                   # No, if it does `import osTimer`, then `osTimer` is the module.
                                   # Then it does `osTimer()`. This means the module is callable? 
                                   # Or maybe `from osTimer import osTimer`? 
                                   # jtt808.py: line 27: `import osTimer`
                                   # line 249: `self.__subpkg_timer[...] = osTimer()`
                                   # This implies the module object itself is callable? That's rare.
                                   # OR, it matches a pattern where the class has the same name as the module and they did `from osTimer import osTimer` but the file checks says `import osTimer`.
                                   # Wait, QuecPython `osTimer` docs say: `from machine import Timer` or similar. 
                                   # Let's look at jtt808.py line 27 again. `import osTimer`.
                                   # If it is `import osTimer`, and usage is `osTimer()`, then `osTimer` must be a class/type that was somehow installed as a top level module?
                                   # To be safe, I will make `sys.modules['osTimer']` an object that is callable and returns a Timer instance.

class OsTimerModule:
    def __call__(self):
        return MockTimer()



class MockQlFs:
    def touch(self, path):
        pass
    def mkdirs(self, path):
        pass
    def path_exists(self, path):
        return False
    def path_getsize(self, path):
        return 0

import re
sys.modules['ure'] = re

import struct
sys.modules['ustruct'] = struct

import binascii
sys.modules['ubinascii'] = binascii

import json
sys.modules['ujson'] = json

sys.modules['ql_fs'] = MockQlFs()
sys.modules['osTimer'] = OsTimerModule()
sys.modules['_thread'] = MockThread()
sys.modules['sim'] = MockSim()
sys.modules['modem'] = MockModem()
sys.modules['rsa'] = type('MockRSA', (), {'gen_keypair': lambda: None, 'get_pubkey': lambda: ('N', '10001')})

# Mocking usocket for common.py (TCPUDPBase)
# common.py imports `usocket`
class MockUSocket:
    AF_INET = socket.AF_INET
    SOCK_STREAM = socket.SOCK_STREAM
    SOCK_DGRAM = socket.SOCK_DGRAM
    SOL_SOCKET = socket.SOL_SOCKET
    IPPROTO_TCP = socket.IPPROTO_TCP
    IPPROTO_UDP = socket.IPPROTO_UDP
    TCP_KEEPALIVE = socket.SO_KEEPALIVE

    def socket(self, af, type, proto=0):
        s = MockSocket(af, type, proto)
        return s
    
    def getaddrinfo(self, host, port):
        return socket.getaddrinfo(host, port)

class MockSocket(socket.socket):
    def getsocketsta(self):
        # Return 4 for Connected (as per logic in common.py)
        # We can try to check real status, but for mock, let's assume connected if we didn't error
        return 4 
    
    def write(self, data):
        return self.send(data)

sys.modules['usocket'] = MockUSocket()


# Add ./usr to path so we can import modules from there
current_dir = os.path.dirname(os.path.abspath(__file__))
usr_dir = os.path.join(current_dir, 'usr')
sys.path.append(current_dir)
sys.path.append(usr_dir)


# Override logger to print to stdout - REMOVED as usr.logging does it already

# Patch `common.py` if needed.
# It uses `usocket`.
# Let's hope the standard socket object we return mimics usocket enough.
# The main difference is usually `setblocking(False)` vs `settimeout`.

if __name__ == "__main__":
    print("Starting JTT808 Client (Mocked)...")
    
    # We need to modify the settings in test_jtt808 to point to localhost if needed, 
    # but the user might want to edit it. 
    # However, let's try to pass arguments or monkeypatch test_jtt808.
    
    import usr.test_jtt808 as test_script
    
    # Monkey patch the init function to use localhost
    original_init = test_script.test_init
    def mocked_init():
        global jtt808_obj
        # Call original but we need to override the object it created?
        # No, test_init creates the object.
        # Let's just override the variables it uses if possible, BUT they are local variables in the function.
        # valid way: Replace the function entirely.
        
        print("Initializing JTT808 Object connecting to 127.0.0.1:8809")
        from usr.jtt808 import JTT808
        
        method = "TCP"
        ip = "127.0.0.1" # Connect to local Laravel server
        port = 8809      # Default port in StartMdvrServer.php
        client_id = "13312345678"
        version = "2019" # User confirmed 2019
        
        test_script.jtt808_obj = JTT808(ip=ip, port=port, method=method, version=version, client_id=client_id)

    test_script.test_init = mocked_init
    
    # Run main
    # We might want to loop or keep it alive, but test_jtt808.main() runs once then exits? 
    # test_jtt808 main calls various test functions.
    # It ends with logout.
    
    try:
        test_script.main()
        print("Test script finished.")
        # Keep alive for a bit to receive responses if threads are running
        time.sleep(5)
    except KeyboardInterrupt:
        print("Stopped by user")
