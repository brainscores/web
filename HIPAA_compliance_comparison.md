# HIPAA Technical Safeguards: McGill VM vs. AWS Migration

This document clarifies the implementation differences between complying with HIPAA technical safeguards on your current self-managed McGill VM versus migrating to AWS.

**Bottom Line Up Front:**
*   **McGill VM (Self-Managed):** You are responsible for **everything**. "Reinventing the wheel" here means writing code and scripts for things that are standard features elsewhere. Your current `upload.php` handles basic auth, but HIPAA requires significantly more rigorous logging, encryption, and audit trails that you would have to build from scratch.
*   **AWS (Managed Services):** You operate under a "Shared Responsibility Model". AWS manages the security *of* the cloud, while you manage security *in* the cloud. Many HIPAA safeguards are checkboxes or specific services (Cognito, CloudTrail, S3) rather than custom PHP code.

---

## Detailed Comparison by HIPAA Rule

### 5. Security Rule – Technical Safeguards

#### **Access Control**
*   **Requirements:** Unique user IDs, Role-based access (RBAC), Privileged access restricted.
*   **Current McGill VM (High Effort):**
    *   **Current State:** Your `upload.php` manually checks `$_SESSION['authenticated']` and queries a `local_users` table.
    *   **Reinventing the Wheel:** You must build your own admin panel to manage users, roles, and permissions. You are responsible for securing the database containing user credentials.
*   **AWS (Medium Effort):**
    *   **Implementation:** Use **AWS IAM** for infrastructure access and **Amazon Cognito** for app users.
    *   **Advantage:** Granular, auditable policies. User pools can handle groups/roles without you managing a `local_users` database table.

#### **Authentication**
*   **Requirements:** MFA enabled, Password policies.
*   **Current McGill VM (High Effort):**
    *   **Current State:** You rely on `password_hash` and `password_verify` in `upload.php`. There is **no MFA** currently implemented.
    *   **Reinventing the Wheel:** You would need to integrate a TOTP library (like Google Authenticator) into your login flow, build the UI for scanning QR codes, and handle recovery codes.
*   **AWS (Low Effort):**
    *   **Implementation:** **Amazon Cognito** handles MFA (SMS/TOTP) and password complexity enforcement automatically.
    *   **Advantage:** You just toggle "Enable MFA" in the console. No code changes needed for the crypto logic.

#### **Audit Controls**
*   **Requirements:** Access logs, PHI activity tracking, Log retention.
*   **Current McGill VM (Very High Effort):**
    *   **Current State:** You use `error_log()` which dumps to a text file. This is **not sufficient** for HIPAA. You need a tamper-proof record of *who* accessed *what* file and *when*.
    *   **Reinventing the Wheel:** You must write a custom audit logger that writes to a secure, separate location. You also need scripts to rotate these logs and ensure they are encrypted and retained for 6 years.
*   **AWS (Low Effort):**
    *   **Implementation:** **AWS CloudTrail** logs every API call. **CloudWatch Logs** collects app logs.
    *   **Advantage:** Logs can be made immutable (WORM compliant) using S3 Object Lock, satisfying the tamper-proof requirement easily.

#### **Transmission Security**
*   **Requirements:** TLS/HTTPS, Secured APIs.
*   **Current McGill VM (Medium Effort):**
    *   **Current State:** Likely relying on manual Apache/Nginx config.
    *   **Reinventing the Wheel:** Manually managing certificates (Certbot) and ensuring weak cipher suites are disabled.
*   **AWS (Low Effort):**
    *   **Implementation:** **AWS Certificate Manager (ACM)** provides free, auto-renewing SSL certs attached to Load Balancers.
    *   **Advantage:** Zero-maintenance SSL/TLS.

#### **Data Integrity**
*   **Requirements:** Tamper protection, Version tracking.
*   **Current McGill VM (High Effort):**
    *   **Current State:** `upload.php` uses `move_uploaded_file()` to save files to disk. If a file is overwritten or corrupted, it is gone.
    *   **Reinventing the Wheel:** You would need to code your own versioning system (e.g., renaming files to `brainscores_uniqueid_v1.nii` on every save) and implement checksum verification.
*   **AWS (Low Effort):**
    *   **Implementation:** Upload directly to **Amazon S3** with "Versioning" enabled.
    *   **Advantage:** Every overwrite saves a new version automatically. You can restore any previous state. S3 checks integrity (checksums) automatically.

#### **Session Controls**
*   **Requirements:** Auto logout, Idle timeouts.
*   **Current McGill VM:**
    *   **Current State:** You manually set `session_set_cookie_params`.
    *   **Reinventing the Wheel:** You must write JS/PHP logic to track idle time and force logout.
*   **AWS:**
    *   **Advantage:** Cognito and API Gateway can handle token expiration and revocation more robustly.

---

### 8. Logging, Backup & Recovery

#### **Monitoring**
*   **Requirements:** PHI access monitoring, Anomaly alerting.
*   **Current McGill VM (High Effort):**
    *   **Reinventing the Wheel:** You need custom scripts to grep logs for suspicious activity (e.g., failed login spikes) and email you.
*   **AWS (Low Effort):**
    *   **Implementation:** **Amazon GuardDuty** automatically detects malicious IP scans or compromised credentials. **CloudWatch Alarms** notify you of errors.

#### **Backup & Disaster Recovery**
*   **Requirements:** Encrypted backups, Disaster recovery plan.
*   **Current McGill VM (Very High Effort):**
    *   **Current State:** No visible backup logic in `upload.php`.
    *   **Reinventing the Wheel:** Writing `cron` jobs to dump the DB, Encrypt it (GPG), and `rsync` to a remote server. You must also back up the `uploads/` directory manually.
*   **AWS (Low Effort):**
    *   **Implementation:** **AWS Backup** and **RDS Automated Backups**.
    *   **Advantage:** Point-in-time recovery to any second in the last 35 days. Cross-region replication for disaster recovery is a checkbox.

---

## Summary Table

| Feature | McGill VM (Self-Managed) | AWS (Managed Services) |
| :--- | :--- | :--- |
| **Authentication** | `local_users` table + Custom PHP logic | Amazon Cognito (Built-in MFA/Policies) |
| **File Storage** | Local Disk (No versioning, Manual backup) | S3 (Versioning + Encryption + Object Lock) |
| **Database** | MySQL installed on OS (Manual patching) | RDS (Automated patching & backups) |
| **Audit Logs** | `error_log` (Insufficient, easily deleted) | CloudTrail (Immutable, tamper-proof) |
| **Encryption** | Manual disk encryption / GPG scripts | AWS KMS (Managed keys) |

### Recommendation
Staying on the **McGill VM** means you are effectively building a custom "HIPAA Compliance Platform" from scratch on top of Linux.
**Migrating to AWS** replaces custom code with managed services, significantly reducing the risk of implementation errors and the burden of ongoing maintenance.
