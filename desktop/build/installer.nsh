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
; Uninstaller additions for the Windows installer (electron-builder nsis.include).
;
; ONE page instead of the default welcome/confirm page (Windows already asks once):
;   - says what is removed: the app, its settings and cache, Start at login
;   - an UNTICKED checkbox "Also delete the Nginx Manager folder" - shown only for the
;     folder the app itself set up (HKCU\Software\Nginx Manager\SetupFolder + the marker
;     data\.nm-app-version), never for any other folder (e.g. a developer's checkout)
;   - an "Uninstall" button
; On a real uninstall (not during an update):
;   - always: app data (deleteAppDataOnUninstall), the updater cache, the Start at login
;     entry, the registry key
;   - folder ticked: scripts\remove-install.ps1 in that folder (one Administrator prompt)
;     stops nginx + PHP, waits until nothing runs from the folder, deletes it with retries
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
    ; downloaded updates
    RMDir /r "$LOCALAPPDATA\nginx-manager-desktop-updater"

    ${If} $nmDeleteFolder == "1"
    ${AndIf} $nmFolder != ""
    ${AndIf} ${FileExists} "$nmFolder\data\.nm-app-version"
      DetailPrint "Stopping nginx and PHP and deleting $nmFolder ..."
      ${If} ${FileExists} "$nmFolder\scripts\remove-install.ps1"
        ; One elevated script stops the servers, waits until nothing runs from the folder,
        ; deletes it with retries and checks the result (exit code 0 = gone).
        ExecWait '"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "$nmFolder\scripts\remove-install.ps1"' $0
      ${Else}
        ; A folder from an older version without remove-install.ps1.
        ExecWait '"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "$nmFolder\stop-all.ps1" -NoPause -WaitElevated'
        Sleep 2000
        RMDir /r "$nmFolder"
      ${EndIf}
      ${If} ${FileExists} "$nmFolder\*.*"
        MessageBox MB_OK|MB_ICONINFORMATION "$nmFolder could not be deleted completely (Administrator permission not given, or files still in use). You can delete the folder by hand."
      ${Else}
        DetailPrint "$nmFolder deleted"
      ${EndIf}
    ${EndIf}

    DeleteRegKey HKCU "Software\Nginx Manager"
  ${EndIf}
!macroend
