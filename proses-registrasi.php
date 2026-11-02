<?php
require_once __DIR__.'/config/database.php';
function fail($m){header('Location: registrasi-klinik.php?error='.urlencode($m));exit;}
$name=trim($_POST['clinic_name']??'');$code=strtolower(trim($_POST['clinic_code']??''));
$clinicEmail=trim($_POST['clinic_email']??'');$phone=trim($_POST['clinic_phone']??'');
$region=(int)($_POST['region_id']??0);$address=trim($_POST['address']??'');
$admin=trim($_POST['admin_name']??'');$email=trim($_POST['admin_email']??'');$adminPhone=trim($_POST['admin_phone']??'');
$pw=$_POST['password']??'';$confirm=$_POST['password_confirmation']??'';
if(!$name||!preg_match('/^[a-z0-9-]{3,40}$/',$code)||!filter_var($clinicEmail,FILTER_VALIDATE_EMAIL)||!$region||!$address||!$admin||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pw)<8)fail('Periksa kembali data formulir. Kode klinik 3-40 karakter (huruf kecil, angka, tanda hubung).');
if($pw!==$confirm)fail('Konfirmasi kata sandi tidak sama.');
$check=$conn->prepare("SELECT id FROM regions WHERE id=? AND region_type='village'");$check->bind_param('i',$region);$check->execute();if(!$check->get_result()->num_rows)fail('Wilayah kelurahan/desa tidak valid.');
$check=$conn->prepare("SELECT id FROM clinics WHERE clinic_code=?");$check->bind_param('s',$code);$check->execute();if($check->get_result()->num_rows)fail('Kode klinik sudah digunakan.');
$check=$conn->prepare("SELECT id FROM users WHERE email=?");$check->bind_param('s',$email);$check->execute();if($check->get_result()->num_rows)fail('Email admin sudah terdaftar.');
try{
$conn->begin_transaction();
$s=$conn->prepare("INSERT INTO clinics(region_id,name,clinic_code,address,phone,email,timezone) VALUES(?,?,?,?,?,?,'Asia/Jakarta')");
$s->bind_param('isssss',$region,$name,$code,$address,$phone,$clinicEmail);$s->execute();$clinicId=$conn->insert_id;
$hash=password_hash($pw,PASSWORD_DEFAULT);
$s=$conn->prepare("INSERT INTO users(name,email,phone,password_hash) VALUES(?,?,?,?)");$s->bind_param('ssss',$admin,$email,$adminPhone,$hash);$s->execute();$userId=$conn->insert_id;
$s=$conn->prepare("SELECT id FROM roles WHERE name='admin' LIMIT 1");$s->execute();$role=$s->get_result()->fetch_assoc();if(!$role)throw new Exception('Role admin belum tersedia. Impor SQL terlebih dahulu.');
$roleId=(int)$role['id'];
$s=$conn->prepare("INSERT INTO user_roles(user_id,role_id,clinic_id) VALUES(?,?,?)");$s->bind_param('iii',$userId,$roleId,$clinicId);$s->execute();
$s=$conn->prepare("INSERT INTO user_clinics(user_id,clinic_id,is_primary) VALUES(?,?,1)");$s->bind_param('ii',$userId,$clinicId);$s->execute();
$conn->commit();header('Location: login.php?registered=1');exit;
}catch(Throwable $e){$conn->rollback();fail('Pendaftaran gagal. Pastikan email/kode klinik belum dipakai dan data wilayah sudah tersedia.');}
