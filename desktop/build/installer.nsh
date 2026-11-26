;******************************************************************************
;*
;*  Project : nginx-manager-2.0
;*  File    : installer.nsh
;*  Date    : 26/11/2026
;*  Author  : Semansys Development Team
;*
;*  Copyright (c) 2026
;*  Tel: +91 97863 37914
;*  Email: dev.digiboost.muthu@gmail.com
;*  All rights reserved, see the LICENSE file for more information.
;*
;******************************************************************************
;
; Installer and uninstaller additions for Windows (electron-builder nsis.include).
;
; ONE page instead of the default welcome/confirm page (Windows already asks once):
;   - says what is removed: the app, its settings and cache, Start at login
;   - an UNTICKED checkbox "Also delete the Nginx Manager folder" - shown only for the
;     folder the app itself set up (HKCU\Software\Nginx Manager\SetupFolder + the marker
;     data\.nm-app-version), never for any other folder (e.g. a developer's checkout)
;   - an "Uninstall" button
; On a real uninstall (not during an update):
;   - always: app data (deleteAppDataOnUninstall), the updater cache, the Start at login
;     entry
;   - folder ticked: remove-install.ps1 from the APP's folder (no window; the uninstaller is
;     already elevated) stops what runs from the server folder and deletes it; only then
;     the folder record (HKCU\Software\Nginx Manager) is removed too
;   - a silent uninstall (/S) never deletes the folder

!include nsDialogs.nsh
!include LogicLib.nsh

!macro customUnWelcomePage
  UninstPage custom un.nmOptionsCreate un.nmOptionsLeave
!macroend

!ifdef BUILD_UNINSTALLER
Var nmFolder
Var nmCheckbox
Var nmDeleteFolder

Function un.nmOptionsCreate
  ; (MUI_HEADER_TEXT is not available yet when electron-builder includes this file - the
  ;  page's own text says what happens.)
  nsDialogs::Create 1018
  Pop $0
  ${If} $0 == error
    Abort
  ${EndIf}

  ${NSD_CreateLabel} 0 0 100% 30u "The Nginx Manager app, its settings and its update cache will be removed, and it will no longer start when you log in."
  Pop $0

  StrCpy $nmCheckbox ""
  ReadRegStr $nmFolder HKCU "Software\Nginx Manager" "SetupFolder"
  ${If} $nmFolder != ""
  ${AndIf} ${FileExists} "$nmFolder\data\.nm-app-version"
    ${NSD_CreateCheckbox} 0 40u 100% 12u "Also delete the Nginx Manager folder:  $nmFolder"
    Pop $nmCheckbox
    ${NSD_CreateLabel} 14u 54u 95% 36u "This removes nginx, PHP, your sites, certificates and settings in that folder. nginx and PHP are stopped first (Windows asks for Administrator). This cannot be undone. Leave it unticked to keep everything."
    Pop $0
  ${EndIf}

  ; The page's main button removes the app.
  GetDlgItem $0 $HWNDPARENT 1
  SendMessage $0 ${WM_SETTEXT} 0 "STR:&Uninstall"

  nsDialogs::Show
FunctionEnd

Function un.nmOptionsLeave
  StrCpy $nmDeleteFolder "0"
  ${If} $nmCheckbox != ""
    ${NSD_GetState} $nmCheckbox $0
    ${If} $0 == ${BST_CHECKED}
      StrCpy $nmDeleteFolder "1"
    ${EndIf}
  ${EndIf}
FunctionEnd
!endif

!macro customUnInstall
  ${IfNot} ${isUpdated}
    ; Start at login (Electron names the value after the app's AppUserModelId)
    DeleteRegValue HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "com.semansys.nginx-manager"
    DeleteRegValue HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "electron.app.Nginx Manager"
    ; downloaded updates - the user's own LocalAppData (a per-machine uninstaller has
    ; switched the shell folders to "all users")
    SetShellVarContext current
    RMDir /r "$LOCALAPPDATA\nginx-manager-desktop-updater"
    SetShellVarContext all

    ${If} $nmDeleteFolder == "1"
    ${AndIf} $nmFolder != ""
    ${AndIf} ${FileExists} "$nmFolder\data\.nm-app-version"
      DetailPrint "Stopping nginx and PHP and deleting $nmFolder ..."
      ; The script runs from the APP's protected folder (still there: this step comes before
      ; the app files are removed), never from the folder being deleted - nothing in that
      ; folder is executed with Administrator rights. It stops what runs from the folder,
      ; deletes it without following links, removes the logon task. nsExec: no window.
      nsExec::Exec '"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "$INSTDIR\resources\nginx-manager\scripts\remove-install.ps1" -Base "$nmFolder"'
      Pop $0
      ${If} ${FileExists} "$nmFolder\*.*"
        MessageBox MB_OK|MB_ICONINFORMATION "$nmFolder could not be deleted completely (files still in use). You can delete the folder by hand."
      ${Else}
        DetailPrint "$nmFolder deleted"
        ; the record goes only with the folder - a kept folder is found again on reinstall
        DeleteRegKey HKCU "Software\Nginx Manager"
      ${EndIf}
    ${EndIf}
  ${EndIf}
!macroend

; Install (and every update): the server folder "C:\Program Files\Nginx Manager Server" -
; NEXT TO the app folder, because every update replaces "C:\Program Files\Nginx Manager"
; completely. The INSTALLER (already Administrator) sets it up from the app's bundle and
; refreshes it on updates; the folder keeps the normal Program Files protection (users read,
; only Administrators write). nginx and PHP run as Administrator, so they can write their
; logs, sites and certificates. No user can change code that later runs elevated.
!macro customInstall
  StrCpy $0 "$PROGRAMFILES64\Nginx Manager Server"
  CreateDirectory "$0"
  ; earlier versions gave every user "Modify" on this folder - back to the inherited
  ; Program Files permissions
  nsExec::ExecToLog '"$SYSDIR\icacls.exe" "$0" /reset /T /C /Q'
  Pop $1
  ; set up / refresh: the app in its setup mode (copies the bundle, keeps .env, sites,
  ; certificates, settings and PHP versions; checks that PHP runs), no window, then quits
  DetailPrint "Setting up Nginx Manager in $0 ..."
  System::Call 'Kernel32::SetEnvironmentVariable(t "NM_SETUP_TEST", t "$0")'
  nsExec::ExecToLog '"$INSTDIR\${APP_EXECUTABLE_FILENAME}"'
  Pop $1
  System::Call 'Kernel32::SetEnvironmentVariable(t "NM_SETUP_TEST", i 0)'
!macroend

; Install, update and uninstall: close the app if it runs - WITHOUT the "Nginx Manager cannot
; be closed" message. Replaces electron-builder's check, which matched every path STARTING
; with the app folder - "C:\Program Files\Nginx Manager Server\nginx.exe" too, so nginx and
; PHP (which it can never close) made it ask "close it manually" on every update. Only
; processes INSIDE "C:\Program Files\Nginx Manager\" are closed (forced, up to 15 s); the
; servers keep running.
!macro customCheckAppRunning
  DetailPrint "Closing Nginx Manager if it is running ..."
  nsExec::Exec `"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -NonInteractive -Command "$$d = '$INSTDIR\'; for ($$i = 0; $$i -lt 15; $$i++) { $$p = @(Get-CimInstance Win32_Process | Where-Object { $$_.ExecutablePath -and $$_.ExecutablePath.StartsWith($$d, [StringComparison]::OrdinalIgnoreCase) }); if ($$p.Count -eq 0) { exit 0 }; $$p | ForEach-Object { Stop-Process -Id $$_.ProcessId -Force -ErrorAction SilentlyContinue }; Start-Sleep -Seconds 1 }; exit 1"`
  Pop $0
  Sleep 500
!macroend
