@echo off
chcp 65001 >nul
setlocal enabledelayedexpansion

set "OUTPUT=design.ini"
set "POSITIONS="

if not "%1"=="" goto manual

if exist "order.txt" (
  echo Using order.txt...
  for /f "usebackq delims=" %%f in ("order.txt") do (
    if exist "%%f" (
      if defined POSITIONS (set "POSITIONS=!POSITIONS!, %%~nf") else (set "POSITIONS=%%~nf")
    )
  )
) else (
  for /f "delims=" %%f in ('dir /b /on *.png *.jpg *.jpeg *.gif *.bmp *.webp *.tga 2^>nul') do (
    if defined POSITIONS (set "POSITIONS=!POSITIONS!, %%~nf") else (set "POSITIONS=%%~nf")
  )
)
goto write

:manual
for %%f in (%*) do (
  if exist "%%f" (
    if defined POSITIONS (set "POSITIONS=!POSITIONS!, %%~nf") else (set "POSITIONS=%%~nf")
  )
)
goto write

:write
> "%OUTPUT%" (
  echo scaling = smooth
  echo positions = %POSITIONS%
)

echo.
echo === %OUTPUT% ===
type "%OUTPUT%"
echo =================
echo.
echo Usage:
echo   %~nx0              - auto (images in folder)
echo   %~nx0 file1 file2  - manual
echo   order.txt nearby   - custom order
pause
