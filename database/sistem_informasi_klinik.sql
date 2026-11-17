-- Sistem Informasi Klinik Multi-Klinik
-- Target: MySQL 8.0+
-- Jalankan pada database kosong. Script membuat database dan 31 tabel.
-- Catatan: script ini membuat struktur tabel, bukan data awal/master data.

CREATE DATABASE IF NOT EXISTS sistem_informasi_klinik
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sistem_informasi_klinik;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Akun, role, permission, wilayah, dan klinik
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NULL UNIQUE,
  phone VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  email_verified_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS regions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  region_type ENUM('province','city','district','village','other') NOT NULL DEFAULT 'other',
  region_code VARCHAR(30) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_regions_parent FOREIGN KEY (parent_id) REFERENCES regions(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_regions_parent (parent_id),
  INDEX idx_regions_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clinics (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  region_id BIGINT UNSIGNED NULL,
  name VARCHAR(180) NOT NULL,
  clinic_code VARCHAR(40) NOT NULL UNIQUE,
  address TEXT NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  timezone VARCHAR(60) NOT NULL DEFAULT 'Asia/Jakarta',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_clinics_region FOREIGN KEY (region_id) REFERENCES regions(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_clinics_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  clinic_id BIGINT UNSIGNED NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id, clinic_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  INDEX idx_user_roles_clinic (clinic_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_clinics (
  user_id BIGINT UNSIGNED NOT NULL,
  clinic_id BIGINT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, clinic_id),
  CONSTRAINT fk_user_clinics_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_user_clinics_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  full_name VARCHAR(150) NOT NULL,
  national_id VARCHAR(40) NULL UNIQUE,
  birth_date DATE NULL,
  sex ENUM('male','female','other','unknown') NOT NULL DEFAULT 'unknown',
  blood_type ENUM('A','B','AB','O','unknown') NOT NULL DEFAULT 'unknown',
  address TEXT NULL,
  emergency_contact_name VARCHAR(150) NULL,
  emergency_contact_phone VARCHAR(30) NULL,
  allergies TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_patients_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_patients_name (full_name),
  INDEX idx_patients_birth_date (birth_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patient_clinics (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id BIGINT UNSIGNED NOT NULL,
  clinic_id BIGINT UNSIGNED NOT NULL,
  medical_record_number VARCHAR(50) NOT NULL,
  registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  CONSTRAINT fk_patient_clinics_patient FOREIGN KEY (patient_id) REFERENCES patients(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_patient_clinics_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  UNIQUE KEY uq_patient_clinic (patient_id, clinic_id),
  UNIQUE KEY uq_clinic_mrn (clinic_id, medical_record_number),
  INDEX idx_patient_clinics_clinic (clinic_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS doctors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  license_number VARCHAR(80) NULL UNIQUE,
  specialization VARCHAR(120) NULL,
  education VARCHAR(180) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doctors_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_doctors_specialization (specialization)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pharmacists (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  license_number VARCHAR(80) NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pharmacists_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 2. Pendaftaran, kunjungan, rekam medis, diagnosis, dan tindakan
CREATE TABLE IF NOT EXISTS registrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clinic_id BIGINT UNSIGNED NOT NULL,
  patient_id BIGINT UNSIGNED NOT NULL,
  doctor_id BIGINT UNSIGNED NULL,
  registration_number VARCHAR(60) NOT NULL,
  registration_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  visit_type ENUM('general','follow_up','emergency','other') NOT NULL DEFAULT 'general',
  complaint TEXT NULL,
  status ENUM('registered','waiting','in_progress','completed','cancelled') NOT NULL DEFAULT 'registered',
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_registrations_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_registrations_patient FOREIGN KEY (patient_id) REFERENCES patients(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_registrations_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_registrations_creator FOREIGN KEY (created_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  UNIQUE KEY uq_registration_number (clinic_id, registration_number),
  INDEX idx_registrations_queue (clinic_id, registration_date, status),
  INDEX idx_registrations_patient (patient_id, registration_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS visits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  registration_id BIGINT UNSIGNED NOT NULL UNIQUE,
  check_in_at DATETIME NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  height_cm DECIMAL(5,2) NULL,
  weight_kg DECIMAL(5,2) NULL,
  temperature_c DECIMAL(4,1) NULL,
  systolic_bp SMALLINT UNSIGNED NULL,
  diastolic_bp SMALLINT UNSIGNED NULL,
  pulse_bpm SMALLINT UNSIGNED NULL,
  respiratory_rate SMALLINT UNSIGNED NULL,
  oxygen_saturation DECIMAL(5,2) NULL,
  notes TEXT NULL,
  CONSTRAINT fk_visits_registration FOREIGN KEY (registration_id) REFERENCES registrations(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medical_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id BIGINT UNSIGNED NOT NULL UNIQUE,
  doctor_id BIGINT UNSIGNED NOT NULL,
  subjective TEXT NULL,
  objective TEXT NULL,
  assessment TEXT NULL,
  plan TEXT NULL,
  notes TEXT NULL,
  status ENUM('draft','finalized','amended') NOT NULL DEFAULT 'draft',
  finalized_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_medical_records_visit FOREIGN KEY (visit_id) REFERENCES visits(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_medical_records_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_medical_records_doctor (doctor_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS diagnoses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NULL,
  name VARCHAR(180) NOT NULL,
  description TEXT NULL,
  coding_system VARCHAR(30) NULL,
  UNIQUE KEY uq_diagnosis_code_system (coding_system, code),
  INDEX idx_diagnoses_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medical_record_diagnoses (
  medical_record_id BIGINT UNSIGNED NOT NULL,
  diagnosis_id BIGINT UNSIGNED NOT NULL,
  diagnosis_type ENUM('primary','secondary') NOT NULL DEFAULT 'secondary',
  notes TEXT NULL,
  PRIMARY KEY (medical_record_id, diagnosis_id),
  CONSTRAINT fk_mrd_record FOREIGN KEY (medical_record_id) REFERENCES medical_records(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_mrd_diagnosis FOREIGN KEY (diagnosis_id) REFERENCES diagnoses(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medical_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NULL UNIQUE,
  name VARCHAR(180) NOT NULL,
  description TEXT NULL,
  default_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medical_record_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  medical_record_id BIGINT UNSIGNED NOT NULL,
  medical_action_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  performed_at DATETIME NULL,
  CONSTRAINT fk_mra_record FOREIGN KEY (medical_record_id) REFERENCES medical_records(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_mra_action FOREIGN KEY (medical_action_id) REFERENCES medical_actions(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_mra_record (medical_record_id)
) ENGINE=InnoDB;

-- 3. Obat, persediaan, resep, dan penyerahan obat
CREATE TABLE IF NOT EXISTS medicines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  medicine_code VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(180) NOT NULL,
  generic_name VARCHAR(180) NULL,
  dosage_form VARCHAR(80) NULL,
  strength VARCHAR(80) NULL,
  unit VARCHAR(40) NOT NULL DEFAULT 'unit',
  description TEXT NULL,
  requires_prescription TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_medicines_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clinic_medicines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clinic_id BIGINT UNSIGNED NOT NULL,
  medicine_id BIGINT UNSIGNED NOT NULL,
  minimum_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_clinic_medicines_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_clinic_medicines_medicine FOREIGN KEY (medicine_id) REFERENCES medicines(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  UNIQUE KEY uq_clinic_medicine (clinic_id, medicine_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medicine_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clinic_medicine_id BIGINT UNSIGNED NOT NULL,
  batch_number VARCHAR(100) NOT NULL,
  expiry_date DATE NULL,
  quantity_on_hand DECIMAL(12,2) NOT NULL DEFAULT 0,
  purchase_price DECIMAL(14,2) NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  supplier_name VARCHAR(180) NULL,
  CONSTRAINT fk_medicine_batches_clinic_medicine FOREIGN KEY (clinic_medicine_id) REFERENCES clinic_medicines(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  UNIQUE KEY uq_medicine_batch (clinic_medicine_id, batch_number),
  INDEX idx_medicine_batches_expiry (expiry_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  medicine_batch_id BIGINT UNSIGNED NOT NULL,
  movement_type ENUM('in','out','adjustment','return','expired') NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  reference_type VARCHAR(60) NULL,
  reference_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  performed_by BIGINT UNSIGNED NULL,
  movement_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_stock_movements_batch FOREIGN KEY (medicine_batch_id) REFERENCES medicine_batches(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_stock_movements_user FOREIGN KEY (performed_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_stock_movements_batch_date (medicine_batch_id, movement_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prescriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  medical_record_id BIGINT UNSIGNED NOT NULL,
  clinic_id BIGINT UNSIGNED NOT NULL,
  prescribed_by BIGINT UNSIGNED NOT NULL,
  prescription_number VARCHAR(60) NOT NULL,
  prescribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('issued','partially_dispensed','dispensed','cancelled') NOT NULL DEFAULT 'issued',
  notes TEXT NULL,
  CONSTRAINT fk_prescriptions_record FOREIGN KEY (medical_record_id) REFERENCES medical_records(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_prescriptions_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_prescriptions_doctor FOREIGN KEY (prescribed_by) REFERENCES doctors(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  UNIQUE KEY uq_prescription_number (clinic_id, prescription_number),
  INDEX idx_prescriptions_status (clinic_id, status, prescribed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prescription_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  prescription_id BIGINT UNSIGNED NOT NULL,
  medicine_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  dosage VARCHAR(180) NULL,
  frequency VARCHAR(120) NULL,
  duration VARCHAR(100) NULL,
  instructions TEXT NULL,
  CONSTRAINT fk_prescription_items_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_prescription_items_medicine FOREIGN KEY (medicine_id) REFERENCES medicines(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_prescription_items_medicine (medicine_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pharmacy_services (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  prescription_id BIGINT UNSIGNED NOT NULL,
  pharmacist_id BIGINT UNSIGNED NULL,
  served_at DATETIME NULL,
  status ENUM('waiting','processing','completed','cancelled') NOT NULL DEFAULT 'waiting',
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pharmacy_services_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_pharmacy_services_pharmacist FOREIGN KEY (pharmacist_id) REFERENCES pharmacists(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_pharmacy_services_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dispensed_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pharmacy_service_id BIGINT UNSIGNED NOT NULL,
  prescription_item_id BIGINT UNSIGNED NOT NULL,
  medicine_batch_id BIGINT UNSIGNED NOT NULL,
  quantity_dispensed DECIMAL(12,2) NOT NULL,
  notes TEXT NULL,
  dispensed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dispensed_items_service FOREIGN KEY (pharmacy_service_id) REFERENCES pharmacy_services(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_dispensed_items_prescription_item FOREIGN KEY (prescription_item_id) REFERENCES prescription_items(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_dispensed_items_batch FOREIGN KEY (medicine_batch_id) REFERENCES medicine_batches(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_dispensed_items_batch (medicine_batch_id)
) ENGINE=InnoDB;

-- 4. Pendukung sistem
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  notification_type VARCHAR(60) NULL,
  related_type VARCHAR(60) NULL,
  related_id BIGINT UNSIGNED NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  INDEX idx_notifications_user_read (user_id, read_at, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  clinic_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NULL,
  entity_id BIGINT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_audit_logs_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_audit_logs_clinic_date (clinic_id, created_at),
  INDEX idx_audit_logs_entity (entity_type, entity_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clinic_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  report_type VARCHAR(100) NOT NULL,
  title VARCHAR(180) NOT NULL,
  parameters JSON NULL,
  file_path VARCHAR(500) NULL,
  status ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  CONSTRAINT fk_reports_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_reports_user FOREIGN KEY (created_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_reports_clinic_date (clinic_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clinic_id BIGINT UNSIGNED NULL,
  setting_key VARCHAR(150) NOT NULL,
  setting_value TEXT NULL,
  description VARCHAR(255) NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_system_settings_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_system_settings_user FOREIGN KEY (updated_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  UNIQUE KEY uq_setting_scope_key (clinic_id, setting_key)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- Data awal role umum (boleh diubah sesuai kebutuhan aplikasi)
INSERT IGNORE INTO roles (name, description) VALUES
  ('admin', 'Administrator sistem/klinik'),
  ('patient', 'Pasien'),
  ('doctor', 'Dokter'),
  ('pharmacist', 'Apoteker atau petugas farmasi');

-- Selesai. Pastikan akun database memiliki izin CREATE DATABASE/CREATE TABLE.
