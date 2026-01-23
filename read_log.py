
import sys

def main():
    try:
        with open('client_v8.log', 'rb') as f:
            content = f.read()
        
        # Try decoding as utf-16-le with error replacement
        decoded = content.decode('utf-16-le', errors='replace')
        
        print(f"File size: {len(content)} bytes")
        print(f"Decoded chars: {len(decoded)}")
        
        found_data = False
        for line in decoded.splitlines():
            if "DEBUG" in line or "ERROR" in line or "Exception" in line or "Traceback" in line:
                print(line)
                found_data = True
                
        if not found_data:
            print("No DEBUG/ERROR lines found. Raw start of file:")
            print(decoded[:500])
            
    except Exception as e:
        print(f"Error reading log: {e}")

if __name__ == "__main__":
    main()
