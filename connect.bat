@echo off
set IP=
set PORT=27016
if not defined IP set /p IP="Server IP: "
if not defined IP goto end

set NAME=FOCC Criminal Case

:: favorite_servers.ini
if exist favorite_servers.ini (
  findstr /b /i "%IP%:%PORT%" favorite_servers.ini >nul
  if errorlevel 1 (
    echo %IP%:%PORT%=%NAME%>>favorite_servers.ini
  )
) else (
  echo %IP%:%PORT%=%NAME%>favorite_servers.ini
)

:: config.ini — прописываем сервер для автоподключения
if exist config.ini (
  findstr /b /i "server=" config.ini >nul
  if errorlevel 1 (
    echo server=%IP%:%PORT%>>config.ini
  ) else (
    powershell -Command "(Get-Content config.ini) -replace '(?i)^server=.*', 'server=%IP%:%PORT%' | Set-Content config.ini"
  )
)

for %%e in (Attorney_Online.exe ao_client.exe AO2.exe) do (
  if exist "%%e" (
    start "" "%%e"
    goto end
  )
)
echo Put connect.bat in AO client folder.
pause
:end
