-- Create the MUSKY 2FA table
CREATE TABLE users_2fa (
  username TEXT PRIMARY KEY,
  password_hash TEXT,
  totp_secret TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  theme TEXT DEFAULT 'light-mode',
  recovery_email TEXT
);
