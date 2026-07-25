import os, time, sys

def main():
    for root, dirs, files in os.walk("./base/"):
        for folder in dirs:
            try:
                os.rename(f"{root}/{folder}", f"{root}/{folder}".lower())
            except:
                pass
        for file in files:
            try:
                os.rename(f"{root}/{file}", f"{root}/{file}".lower())
            except:
                pass
    return

if __name__ == "__main__":
    start_time = time.time()
    main()
    print("Program execution took %s seconds." % (time.time() - start_time))
