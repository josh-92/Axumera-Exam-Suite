# Backup and recovery

Before any future update or manual database maintenance, stop `AxumeraServer.exe` and create an offline backup of `data/mariadb`, `application/eaes_exam_system/.env`, `application/eaes_exam_system/storage/license.lic`, and customer uploads. Store backups outside the installation directory with restricted access.

To recover from a runtime failure, preserve these paths first, restore verified runtime/application files, then start the controller and verify `/health.php`. Do not delete or replace `data/mariadb` to repair an application issue. Never use the fresh installer as an update or recovery tool.

`Axumera_Update.exe` is not implemented. Consequently there is no tested automated rollback or migration procedure; the required future-update contract is documented in `UPDATE_ARCHITECTURE.md`.
