@echo off
setlocal DisableDelayedExpansion
if not exist "%~dp0..\Runtime\node\node.exe" goto :incomplete
if not exist "%~dp0..\Code\tools\windows-launcher.mjs" goto :incomplete
if not exist "%~dp0..\MANIFEST.json" goto :incomplete
if not exist "%~dp0..\Code\tools\portable.mjs" goto :incomplete
if /i "%~1"=="--verify-paths" goto :verify
"%~dp0..\Runtime\node\node.exe" "%~dp0..\Code\tools\windows-launcher.mjs" add-client
set "cw_api_result=%ERRORLEVEL%"
goto :done
:verify
"%~dp0..\Runtime\node\node.exe" --version
set "cw_api_result=%ERRORLEVEL%"
if not "%cw_api_result%"=="0" goto :done
echo PATHS VERIFIED: required files exist and bundled Node runs.
echo Verification only: no installation, no service start, no EAI call.
goto :done
:incomplete
echo INCOMPLETE PACKAGE: a required file is missing beside this launcher.
echo Do not run these files directly inside the ZIP.
echo Right-click the ZIP, then Extract All. Keep Code, Runtime and Installation together.
echo Keep the complete extracted package. Long paths are handled by the launcher.
echo No installation or EAI collection was started.
set "cw_api_result=10"
:done
if not defined CW_API_NO_PAUSE pause
exit /b %cw_api_result%
