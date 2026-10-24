@echo off
setlocal DisableDelayedExpansion
if not exist "%~dp0..\Runtime\node\node.exe" goto :incomplete
if not exist "%~dp0..\Code\tools\update-local.mjs" goto :incomplete
if not exist "%~dp0..\MANIFEST.json" goto :incomplete
if /i "%~1"=="--verify-paths" goto :verify
"%~dp0..\Runtime\node\node.exe" "%~dp0..\Code\tools\update-local.mjs" --interactive
set "cw_api_result=%ERRORLEVEL%"
goto :done
:verify
"%~dp0..\Runtime\node\node.exe" --version
set "cw_api_result=%ERRORLEVEL%"
if not "%cw_api_result%"=="0" goto :done
echo PATHS VERIFIED: bundled Node and update helper exist.
goto :done
:incomplete
echo INCOMPLETE PACKAGE: extract the complete approved runtime ZIP first.
set "cw_api_result=10"
:done
if not defined CW_API_NO_PAUSE pause
exit /b %cw_api_result%
