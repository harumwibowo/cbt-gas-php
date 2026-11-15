/**
 * CBT-GAS (Google Apps Script Computer Based Test)
 * Version: 2.1.0 (Dual Layer Autosave & Strict Anti-Cheat)
 * Backend Logic: Authentication, Database Handler, Session Manager, Exam API, Autosave Processor
 */

// ==========================================
// 1. DATABASE & SPREADSHEET INITIALIZATION
// ==========================================

const SHEETS = {
  USERS: 'Users',
  SESI: 'Sesi',
  EXAMS: 'Exams',
  QUESTIONS: 'Questions',
  TEKS_UJIAN: 'TeksUjian',
  RESULTS: 'Results',
  LOGS: 'Logs',
  SETTINGS: 'Settings'
};

const SHEET_HEADERS = {
  Users: ['id_user', 'username', 'password', 'nama_lengkap', 'role', 'kelas', 'token_session', 'status_aktif', 'sesi', 'tingkat'],
  Sesi: ['id_sesi', 'nama_sesi', 'keterangan'],
  Exams: ['id_ujian', 'nama_ujian', 'durasi_menit', 'waktu_mulai', 'waktu_selesai', 'acak_soal', 'acak_opsi', 'token_ujian', 'kelas', 'id_guru', 'sesi', 'tipe_peserta', 'peserta_khusus', 'kolaborator', 'tingkat', 'jumlah_soal_tampil', 'tampilkan_pembahasan', 'jumlah_percobaan', 'max_violations', 'tindakan_pelanggaran'],
  Questions: [
    'id_soal', 'id_ujian', 'teks_soal', 'url_gambar',
    'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'opsi_e', 'opsi_f', 'opsi_g', 'opsi_h', 'opsi_i', 'opsi_j',
    'kunci_jawaban', 'bobot', 'id_teks', 'pembahasan'
  ],
  TeksUjian: ['id_teks', 'id_ujian', 'nama_teks', 'isi_teks'],
  Results: ['id_hasil', 'id_ujian', 'username_siswa', 'waktu_mulai', 'waktu_submit', 'jumlah_pelanggaran', 'nilai_akhir', 'status', 'detail_jawaban', 'soal_terpilih', 'percobaan_ke'],
  Logs: ['timestamp', 'username', 'jenis_kejadian', 'keterangan'],
  Settings: ['kunci', 'nilai', 'keterangan']
};

/**
 * Mendapatkan referensi Spreadsheet.
 * Mendukung bound script (container-bound) maupun standalone via Script Properties.
 */
function getSpreadsheet() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  if (ss) return ss;
  
  var props = PropertiesService.getScriptProperties();
  var sheetId = props.getProperty('SPREADSHEET_ID');
  if (sheetId) {
    try {
      return SpreadsheetApp.openById(sheetId);
    } catch (e) {
      Logger.log("Gagal membuka spreadsheet dengan ID: " + sheetId);
    }
  }
  
  throw new Error("Spreadsheet belum terhubung. Jalankan fungsi CreateDb() terlebih dahulu dari menu Editor Apps Script.");
}

/**
 * FUNGSI UTAMA SESUAI PRD: CreateDb()
 * Membuat sheet, header, dan inisialisasi akun admin jika belum ada.
 * Bersifat IDEMPOTENT: juga mendeteksi sheet/kolom yang hilang pada database
 * yang sudah ada (migrasi) dan melengkapinya tanpa mengubah urutan kolom lama.
 */
function CreateDb() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var isStandalone = false;
  
  if (!ss) {
    isStandalone = true;
    var props = PropertiesService.getScriptProperties();
    var existingId = props.getProperty('SPREADSHEET_ID');
    if (existingId) {
      try {
        ss = SpreadsheetApp.openById(existingId);
      } catch (e) {
        ss = null;
      }
    }
    
    if (!ss) {
      ss = SpreadsheetApp.create('CBT_GAS_Database');
      props.setProperty('SPREADSHEET_ID', ss.getId());
      Logger.log('Spreadsheet baru dibuat: ' + ss.getUrl());
    }
  }
  
  var sheetNames = Object.keys(SHEET_HEADERS);
  sheetNames.forEach(function(name) {
    var headers = SHEET_HEADERS[name];
    var sheet = ss.getSheetByName(name);
    var isNewSheet = false;

    // 1. Deteksi & buat sheet yang belum ada
    if (!sheet) {
      sheet = ss.insertSheet(name);
      isNewSheet = true;
    }

    // 2. Inisialisasi Header jika sheet masih kosong
    if (sheet.getLastRow() === 0) {
      sheet.appendRow(headers);
      var headerRange = sheet.getRange(1, 1, 1, headers.length);
      headerRange.setFontWeight('bold');
      headerRange.setBackground('#1e293b');
      headerRange.setFontColor('#ffffff');
      sheet.setFrozenRows(1);
    } else {
      // 3. Migrasi: deteksi kolom yang BELUM ADA pada sheet yang sudah terisi
      //    dan lengkapi kolom baru tanpa mengubah urutan kolom lama.
      var currentHeaders = sheet.getRange(1, 1, 1, Math.max(sheet.getLastColumn(), 1))
        .getValues()[0]
        .map(function(h) {
          return toSafeString(h).toLowerCase();
        });

      var columnAdded = false;
      headers.forEach(function(th) {
        if (currentHeaders.indexOf(th.toLowerCase()) === -1) {
          var newCol = sheet.getLastColumn() + 1;
          sheet.getRange(1, newCol).setValue(th).setFontWeight('bold').setBackground('#1e293b').setFontColor('#ffffff');
          currentHeaders.push(th.toLowerCase());
          columnAdded = true;
          Logger.log('Kolom baru "' + th + '" ditambahkan ke sheet ' + name + '.');
        }
      });

      if (columnAdded && sheet.getFrozenRows() === 0) {
        try { sheet.setFrozenRows(1); } catch (e) {}
      }
    }

    // 4. Penomoran teks untuk sheet data utama (agar ID/teks tidak berubah jadi angka)
    if (name === SHEETS.EXAMS || name === SHEETS.QUESTIONS || name === SHEETS.TEKS_UJIAN) {
      try { sheet.getRange(1, 1, sheet.getMaxRows(), sheet.getMaxColumns()).setNumberFormat('@'); } catch (e) {}
    }

    if (isNewSheet) {
      Logger.log('Sheet baru dibuat: ' + name);
    }
  });

  // 5. Normalisasi opsi "Jumlah Percobaan" ujian (default 1, maksimum 99).
  //    Baris ujian lama yang belum memiliki nilai akan diisi 1 agar seluruh ujian
  //    dapat dikerjakan tepat satu kali seperti perilaku sebelumnya.
  var examsSheetAttempts = ss.getSheetByName(SHEETS.EXAMS);
  if (examsSheetAttempts && examsSheetAttempts.getLastRow() > 1) {
    var attemptsMap = getHeaderMap(examsSheetAttempts);
    var attemptsCol = attemptsMap['jumlah_percobaan'] !== undefined ? attemptsMap['jumlah_percobaan'] : -1;
    if (attemptsCol !== -1) {
      var attemptsLastRow = examsSheetAttempts.getLastRow();
      var attemptsValues = examsSheetAttempts.getRange(2, attemptsCol + 1, attemptsLastRow - 1, 1).getValues();
      var attemptsChanged = false;
      for (var av = 0; av < attemptsValues.length; av++) {
        var rawAttempts = toSafeString(attemptsValues[av][0]).trim();
        var normalizedAttempts = normalizeExamAttempts(rawAttempts);
        if (rawAttempts !== String(normalizedAttempts)) {
          attemptsValues[av][0] = String(normalizedAttempts);
          attemptsChanged = true;
        }
      }
      if (attemptsChanged) {
        examsSheetAttempts.getRange(2, attemptsCol + 1, attemptsLastRow - 1, 1).setNumberFormat('@').setValues(attemptsValues);
        Logger.log('Opsi jumlah_percobaan pada sheet Exams dinormalisasi (default 1).');
      }
    }
  }

  // 5b. Migrasi opsi "Batas Maksimal Pelanggaran" & "Tindakan Saat Maks Pelanggaran Tercapai"
  //     dari pengaturan GLOBAL (sheet Settings) menjadi PER-UJIAN (kolom Exams).
  //     Nilai global lama disalin ke setiap ujian yang belum memiliki nilai.
  var legacyMaxViolations = '';
  var legacyViolationAction = '';
  var legacySettingsSheet = ss.getSheetByName(SHEETS.SETTINGS);
  if (legacySettingsSheet && legacySettingsSheet.getLastRow() > 1) {
    var legacySettingsData = legacySettingsSheet.getDataRange().getValues();
    for (var lg = 1; lg < legacySettingsData.length; lg++) {
      var lgKey = toSafeString(legacySettingsData[lg][0]).toLowerCase().trim();
      if (lgKey === 'max_violations') legacyMaxViolations = toSafeString(legacySettingsData[lg][1]).trim();
      if (lgKey === 'tindakan_pelanggaran') legacyViolationAction = toSafeString(legacySettingsData[lg][1]).trim();
    }
  }

  var examsViolationSheet = ss.getSheetByName(SHEETS.EXAMS);
  if (examsViolationSheet && examsViolationSheet.getLastRow() > 1) {
    var violationMap = getHeaderMap(examsViolationSheet);
    var maxViolationCol = violationMap['max_violations'] !== undefined ? violationMap['max_violations'] : -1;
    var actionViolationCol = violationMap['tindakan_pelanggaran'] !== undefined ? violationMap['tindakan_pelanggaran'] : -1;
    if (maxViolationCol !== -1 || actionViolationCol !== -1) {
      var violationLastRow = examsViolationSheet.getLastRow();
      var violationLastCol = examsViolationSheet.getLastColumn();
      var violationValues = examsViolationSheet.getRange(2, 1, violationLastRow - 1, violationLastCol).getValues();
      var violationChanged = false;
      for (var vr = 0; vr < violationValues.length; vr++) {
        if (maxViolationCol !== -1) {
          var rawMaxViolation = toSafeString(violationValues[vr][maxViolationCol]).trim();
          var normalizedMaxViolation = rawMaxViolation
            ? normalizeExamMaxViolations(rawMaxViolation)
            : (legacyMaxViolations ? normalizeExamMaxViolations(legacyMaxViolations) : 3);
          if (rawMaxViolation !== String(normalizedMaxViolation)) {
            violationValues[vr][maxViolationCol] = String(normalizedMaxViolation);
            violationChanged = true;
          }
        }
        if (actionViolationCol !== -1) {
          var rawViolationAction = toSafeString(violationValues[vr][actionViolationCol]).trim().toLowerCase();
          var normalizedViolationAction = rawViolationAction
            ? normalizeViolationAction(rawViolationAction)
            : normalizeViolationAction(legacyViolationAction || 'auto_submit');
          if (rawViolationAction !== normalizedViolationAction) {
            violationValues[vr][actionViolationCol] = normalizedViolationAction;
            violationChanged = true;
          }
        }
      }
      if (violationChanged) {
        examsViolationSheet.getRange(2, 1, violationLastRow - 1, violationLastCol).setNumberFormat('@').setValues(violationValues);
        Logger.log('Opsi batas & tindakan pelanggaran pada sheet Exams dinormalisasi (per-ujian).');
      }
    }
  }

  // 6. Normalisasi kolom Results.percobaan_ke (baris lama dianggap percobaan ke-1)
  var resultsSheetAttempts = ss.getSheetByName(SHEETS.RESULTS);
  if (resultsSheetAttempts && resultsSheetAttempts.getLastRow() > 1) {
    var resultAttemptsMap = getHeaderMap(resultsSheetAttempts);
    var resultAttemptsCol = resultAttemptsMap['percobaan_ke'] !== undefined ? resultAttemptsMap['percobaan_ke'] : -1;
    if (resultAttemptsCol !== -1) {
      var resultAttemptsLastRow = resultsSheetAttempts.getLastRow();
      var resultAttemptsValues = resultsSheetAttempts.getRange(2, resultAttemptsCol + 1, resultAttemptsLastRow - 1, 1).getValues();
      var resultAttemptsChanged = false;
      for (var rv = 0; rv < resultAttemptsValues.length; rv++) {
        var rawResultAttempts = toSafeString(resultAttemptsValues[rv][0]).trim();
        var normalizedResultAttempts = normalizeExamAttempts(rawResultAttempts);
        if (rawResultAttempts !== String(normalizedResultAttempts)) {
          resultAttemptsValues[rv][0] = String(normalizedResultAttempts);
          resultAttemptsChanged = true;
        }
      }
      if (resultAttemptsChanged) {
        resultsSheetAttempts.getRange(2, resultAttemptsCol + 1, resultAttemptsLastRow - 1, 1).setNumberFormat('@').setValues(resultAttemptsValues);
        Logger.log('Kolom percobaan_ke pada sheet Results dinormalisasi (percobaan ke-1).');
      }
    }
  }

  // Hapus 'Sheet1' default jika ada
  var defaultSheet = ss.getSheetByName('Sheet1') || ss.getSheetByName('Sheet 1');
  if (defaultSheet && ss.getSheets().length > 1) {
    try {
      ss.deleteSheet(defaultSheet);
    } catch (e) {}
  }

  // Periksa dan buat default Sesi jika sheet Sesi kosong
  var sesiSheet = ss.getSheetByName(SHEETS.SESI);
  if (sesiSheet && sesiSheet.getLastRow() <= 1) {
    sesiSheet.appendRow(['SES-1', 'Sesi 1', 'Sesi Pagi (07.30 - 09.30)']);
    sesiSheet.appendRow(['SES-2', 'Sesi 2', 'Sesi Siang (10.00 - 12.00)']);
    Logger.log('Data default Sesi berhasil dibuat (Sesi 1 & Sesi 2).');
  }
  
  // Periksa dan buat default akun Admin jika Users masih kosong
  var usersSheet = ss.getSheetByName(SHEETS.USERS);
  var usersData = usersSheet.getDataRange().getValues();
  
  var hasAdmin = false;
  if (usersData.length > 1) {
    for (var i = 1; i < usersData.length; i++) {
      if (usersData[i][1] === 'admin') {
        hasAdmin = true;
        break;
      }
    }
  }
  
  if (!hasAdmin) {
    // ID User, username, password, nama_lengkap, role, kelas, token_session, status_aktif, sesi
    var adminId = 'USR-' + Utilities.getUuid().substring(0, 8).toUpperCase();
    usersSheet.appendRow([adminId, 'admin', 'admin123', 'Administrator CBT', 'Admin', 'Semua', '', 'Aktif', 'Semua']);
    Logger.log('Akun default Admin berhasil dibuat (admin / admin123).');
  }

  // Inisialisasi & Sinkronisasi Pengaturan Default di Sheet Settings
  var settingsSheet = ss.getSheetByName(SHEETS.SETTINGS);
  if (settingsSheet) {
    var sData = settingsSheet.getDataRange().getValues();
    var existingKeys = {};
    for (var sk = 1; sk < sData.length; sk++) {
      existingKeys[toSafeString(sData[sk][0]).toLowerCase().trim()] = true;
    }
    var defaultSettings = [
      ['nama_sekolah', 'SMA / SMK Digital Platform', 'Nama sekolah atau institusi penyelenggara'],
      ['logo_url', '', 'URL gambar logo sekolah'],
      ['tahun_ajaran', '2026/2027', 'Tahun ajaran / semester aktif'],
      ['max_student_sessions', '1', 'Batas maksimal sesi login bersamaan untuk siswa (jumlah perangkat berbeda)'],
      ['allowed_student_browsers', '', 'Daftar browser yang diizinkan untuk login siswa, dipisah koma (mis. SEB, Chrome). Kosong = semua browser diizinkan'],
      ['wajib_fullscreen', 'Ya', 'Wajib mode layar penuh selama ujian (Ya/Tidak)'],
      ['deteksi_tab_switch', 'Ya', 'Deteksi berpindah tab atau kehilangan fokus (Ya/Tidak)'],
      ['blokir_aksi', 'Ya', 'Blokir klik kanan, copy, paste, dan pintasan keyboard (Ya/Tidak)'],
      ['dynamic_watermark', 'Ya', 'Tampilkan watermark identitas siswa melayang di area soal (Ya/Tidak)'],
      ['gemini_api_key', '', 'Google Gemini API Key untuk fitur generate soal AI'],
      ['gemini_model', 'gemini-2.0-flash', 'Model Gemini yang digunakan untuk generate konten']
    ];
    var appendedCount = 0;
    defaultSettings.forEach(function(row) {
      var kLower = row[0].toLowerCase().trim();
      if (!existingKeys[kLower]) {
        settingsSheet.appendRow(row);
        existingKeys[kLower] = true;
        appendedCount++;
      }
    });
    if (appendedCount > 0) {
      Logger.log('Pengaturan baru (' + appendedCount + ' baris) berhasil diselaraskan ke sheet Settings.');
    }

    // Migrasi: "max_violations" & "tindakan_pelanggaran" kini menjadi pengaturan
    // PER-UJIAN (kolom Exams), sehingga baris pengaturan global lama dihapus.
    var legacyViolationKeys = ['max_violations', 'tindakan_pelanggaran'];
    var rowsToDelete = [];
    for (var lr = sData.length - 1; lr >= 1; lr--) {
      var legacyRowKey = toSafeString(sData[lr][0]).toLowerCase().trim();
      if (legacyViolationKeys.indexOf(legacyRowKey) !== -1) {
        rowsToDelete.push(lr + 1);
      }
    }
    rowsToDelete.forEach(function(rowIndex) {
      settingsSheet.deleteRow(rowIndex);
    });
    if (rowsToDelete.length > 0) {
      Logger.log('Pengaturan global pelanggaran (' + rowsToDelete.length + ' baris) dipindahkan menjadi pengaturan per-ujian.');
    }
  }
  
  logActivity('SYSTEM', 'Initialize Database', 'Database & Akun Admin berhasil diinisialisasi.');
  
  var result = {
    success: true,
    message: 'Database & Pengaturan berhasil diinisialisasi!',
    spreadsheetUrl: ss.getUrl(),
    adminCredentials: { username: 'admin', password: 'admin123' }
  };
  
  Logger.log(result);
  return result;
}

// ==========================================
// 2. HTTP ROUTING & WEB APP SERVING
// ==========================================

function doGet(e) {
  var template = HtmlService.createTemplateFromFile('Index');
  return template.evaluate()
    .setTitle('CBT GAS - Platform Ujian Online')
    .addMetaTag('viewport', 'width=device-width, initial-scale=1.0')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

// ==========================================
// 3. LOGGING UTILITY
// ==========================================

function logActivity(username, jenisKejadian, keterangan) {
  try {
    var ss = getSpreadsheet();
    var sheet = ss.getSheetByName(SHEETS.LOGS);
    if (!sheet) return;
    
    var timestamp = Utilities.formatDate(new Date(), Session.getScriptTimeZone() || 'Asia/Jakarta', 'dd-MM-yyyy HH:mm:ss');
    sheet.appendRow([timestamp, username || 'Anonymous', jenisKejadian, keterangan || '']);
  } catch (err) {
    Logger.log('Gagal mencatat log: ' + err.toString());
  }
}

// ==========================================
// 4. AUTENTIKASI & SESI (SINGLE SESSION LOCK)
// ==========================================

function login(username, password, userAgent) {
  var lock = LockService.getScriptLock();
  lock.tryLock(10000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.USERS);
    if (!sheet) {
      CreateDb();
      sheet = getSheetSafe(ss, SHEETS.USERS);
    }
    
    var data = sheet.getDataRange().getValues();
    if (data.length <= 1) {
      CreateDb();
      data = sheet.getDataRange().getValues();
    }
    
    var map = getHeaderMap(sheet);
    var idCol = map['id_user'] !== undefined ? map['id_user'] : 0;
    var uCol = map['username'] !== undefined ? map['username'] : 1;
    var pCol = map['password'] !== undefined ? map['password'] : 2;
    var nCol = map['nama_lengkap'] !== undefined ? map['nama_lengkap'] : 3;
    var rCol = map['role'] !== undefined ? map['role'] : 4;
    var kCol = map['kelas'] !== undefined ? map['kelas'] : 5;
    var tCol = map['token_session'] !== undefined ? map['token_session'] : 6;
    var sCol = map['status_aktif'] !== undefined ? map['status_aktif'] : 7;
    var sesiCol = map['sesi'] !== undefined ? map['sesi'] : -1;
    
    username = (username || '').trim().toLowerCase();
    password = (password || '').trim();
    
    var userFound = null;
    var userRowIndex = -1;
    
    for (var i = 1; i < data.length; i++) {
      var row = data[i];
      var rowUsername = toSafeString(row[uCol]).toLowerCase();
      var rowPassword = toSafeString(row[pCol]);
      var rowStatus = toSafeString(row[sCol]).toLowerCase();
      
      if (rowUsername === username) {
        if (rowPassword !== password) {
          return { success: false, message: 'Password salah. Silakan periksa kembali.' };
        }
        if (rowStatus && rowStatus !== 'aktif') {
          return { success: false, message: 'Akun Anda berstatus non-aktif. Hubungi Admin.' };
        }
        
        userRowIndex = i + 1; // 1-based index
        userFound = {
          id_user: toSafeString(row[idCol]),
          username: toSafeString(row[uCol]),
          nama_lengkap: toSafeString(row[nCol]),
          role: toSafeString(row[rCol]) || 'Siswa',
          kelas: toSafeString(row[kCol]) || '-',
          status_aktif: toSafeString(row[sCol]) || 'Aktif',
          sesi: sesiCol !== -1 ? (toSafeString(row[sesiCol]) || '-') : '-'
        };
        break;
      }
    }
    
    if (!userFound) {
      return { success: false, message: 'Username tidak ditemukan.' };
    }
    
    // Pembatasan browser login (HANYA untuk siswa). Daftar browser yang diizinkan
    // diatur di halaman Pengaturan (setting "allowed_student_browsers"), mis. "SEB, Chrome".
    var isStudent = (userFound.role || '').trim().toLowerCase() === 'siswa';
    if (isStudent) {
      var allowedBrowsers = getAllowedStudentBrowsers();
      if (!isStudentBrowserAllowed(userAgent, allowedBrowsers)) {
        var detectedBrowser = detectUserAgentBrowser(userAgent);
        var detectedLabel = detectedBrowser ? detectedBrowser.toUpperCase() : 'Tidak Dikenali';
        logActivity(userFound.username, 'Login Ditolak',
          'Browser tidak diizinkan (' + detectedLabel + '). Browser diizinkan: ' + allowedBrowsers);
        return {
          success: false,
          message: 'Login ditolak. Browser yang Anda gunakan (' + detectedLabel + ') tidak termasuk browser yang diizinkan.' +
            ' Browser yang diizinkan: ' + allowedBrowsers + '. Silakan hubungi pengawas/guru.'
        };
      }
    }
    
    // Generate new Session Token (Multi Session Lock untuk Siswa)
    // Akun Admin/Guru tetap single session. Siswa dibatasi oleh pengaturan
    // "max_student_sessions" (default 1 = 1 perangkat). Bila batas sudah tercapai,
    // sesi login paling lama otomatis dikeluarkan agar siswa tidak terkunci total.
    var maxSessions = isStudent ? getMaxStudentSessions() : 1;
    var oldTokens = parseSessionTokens(data[userRowIndex - 1][tCol]);
    var sessionToken = 'TKN-' + Utilities.getUuid();
    var newTokenList = buildSessionTokenList(oldTokens, sessionToken, maxSessions);
    sheet.getRange(userRowIndex, tCol + 1).setValue(newTokenList.join(',')); // 1-based column
    SpreadsheetApp.flush();
    
    var kickedCount = 0;
    for (var oi = 0; oi < oldTokens.length; oi++) {
      if (newTokenList.indexOf(oldTokens[oi]) === -1) kickedCount++;
    }
    
    logActivity(userFound.username, 'Login', 'Login berhasil dari browser/perangkat' +
      (detectUserAgentBrowser(userAgent) ? ' (' + detectUserAgentBrowser(userAgent).toUpperCase() + ')' : '') + '.' +
      (kickedCount > 0 ? ' ' + kickedCount + ' sesi login lama dikeluarkan (batas maksimal ' + maxSessions + ' sesi).' : ''));
    
    return {
      success: true,
      token: sessionToken,
      user: userFound
    };
  } catch (e) {
    return { success: false, message: 'Kesalahan server saat login: ' + e.message };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Validasi session token untuk setiap request.
 */
/**
 * Helper: Mencari Sheet dengan case-insensitive & trim
 */
function getSheetSafe(ss, sheetName) {
  if (!ss) ss = getSpreadsheet();
  var sheet = ss.getSheetByName(sheetName);
  if (sheet) return sheet;
  
  var sheets = ss.getSheets();
  var target = (sheetName || '').trim().toLowerCase();
  for (var i = 0; i < sheets.length; i++) {
    if (sheets[i].getName().trim().toLowerCase() === target) {
      return sheets[i];
    }
  }
  return null;
}

/**
 * Helper: Memetakan nama kolom ke indeks 0-based
 */
function getHeaderMap(sheet) {
  if (!sheet || sheet.getLastRow() === 0) return {};
  var headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
  var map = {};
  for (var i = 0; i < headers.length; i++) {
    var h = (headers[i] || '').toString().trim().toLowerCase();
    if (h) map[h] = i;
  }
  return map;
}

/**
 * Helper: Konversi nilai sel ke string aman (termasuk penanganan Date)
 */
function toSafeString(val) {
  if (val === null || val === undefined) return '';
  if (val instanceof Date) {
    try {
      var h = val.getHours();
      var m = val.getMinutes();
      var s = val.getSeconds();
      if (h === 0 && m === 0 && s === 0) {
        var months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        var currentYear = new Date().getFullYear();
        var yStr = val.getFullYear() !== currentYear ? ' ' + val.getFullYear() : '';
        return val.getDate() + ' ' + months[val.getMonth()] + yStr;
      }
      return Utilities.formatDate(val, Session.getScriptTimeZone() || 'Asia/Jakarta', 'dd-MM-yyyy HH:mm:ss');
    } catch (e) {
      return val.toISOString();
    }
  }
  return val.toString().trim();
}

/**
 * Helper: Standarisasi format tanggal/waktu ke format ISO (YYYY-MM-DDTHH:mm).
 * Memastikan nilai waktu_mulai dan waktu_selesai tidak hilang atau corrupt saat disimpan ke Google Sheets.
 */
function formatDateTimeIso(val) {
  if (val === null || val === undefined) return '';
  if (val instanceof Date) {
    if (isNaN(val.getTime())) return '';
    return Utilities.formatDate(val, Session.getScriptTimeZone() || 'Asia/Jakarta', "yyyy-MM-dd'T'HH:mm");
  }
  var str = String(val).trim();
  if (!str || str === '-' || str.toLowerCase() === 'kapan saja') return '';
  // Jika formatnya sudah yyyy-MM-ddTHH:mm atau yyyy-MM-ddTHH:mm:ss
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(str)) {
    return str.substring(0, 16);
  }
  // Jika formatnya dd-MM-yyyy HH:mm:ss atau dd/MM/yyyy HH:mm
  var dmyMatch = str.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?/);
  if (dmyMatch) {
    var d = ('0' + dmyMatch[1]).slice(-2);
    var m = ('0' + dmyMatch[2]).slice(-2);
    var y = dmyMatch[3];
    var hr = dmyMatch[4] ? ('0' + dmyMatch[4]).slice(-2) : '00';
    var mn = dmyMatch[5] ? ('0' + dmyMatch[5]).slice(-2) : '00';
    return y + '-' + m + '-' + d + 'T' + hr + ':' + mn;
  }
  // Coba parse dengan Date constructor
  try {
    var parsed = new Date(str);
    if (!isNaN(parsed.getTime())) {
      return Utilities.formatDate(parsed, Session.getScriptTimeZone() || 'Asia/Jakarta', "yyyy-MM-dd'T'HH:mm");
    }
  } catch (e) {}
  return str;
}

/**
 * Validasi session token untuk setiap request.
 */
function validateSession(username, token) {
  if (!username || !token) return false;
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.USERS);
    if (!sheet) return false;
    
    var data = sheet.getDataRange().getValues();
    if (data.length <= 1) return false;
    
    var headerMap = getHeaderMap(sheet);
    var uIdx = headerMap['username'] !== undefined ? headerMap['username'] : 1;
    var tIdx = headerMap['token_session'] !== undefined ? headerMap['token_session'] : 6;
    
    var targetUname = username.toString().trim().toLowerCase();
    var targetToken = token.toString().trim();
    
    for (var i = 1; i < data.length; i++) {
      var rowUsername = toSafeString(data[i][uIdx]).toLowerCase();
      var activeTokens = parseSessionTokens(data[i][tIdx]);
      if (rowUsername === targetUname) {
        return activeTokens.indexOf(targetToken) !== -1;
      }
    }
  } catch (e) {
    Logger.log("Error validateSession: " + e.message);
  }
  return false;
}

/**
 * Status sesi untuk heartbeat klien (tanpa efek samping).
 * Dipanggil berkala oleh frontend agar pengguna yang sesinya dikeluarkan
 * (Reset Sesi oleh Admin/Guru atau login di perangkat lain) segera
 * terlempar ke halaman login.
 *
 * @param {string} username - Username aktif
 * @param {string} token - Token sesi
 * @return {Object} { success, valid, message }
 */
function getSessionStatus(username, token) {
  try {
    return { success: true, valid: validateSession(username, token) === true };
  } catch (e) {
    // Gagal memverifikasi = jangan anggap sesi tidak valid (fail-open)
    return { success: false, valid: true, message: e.message };
  }
}

/**
 * Logout pengguna: menghapus session token dari database & mencatat log.
 */
function logout(username, token) {
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.USERS);
    if (sheet) {
      var data = sheet.getDataRange().getValues();
      var map = getHeaderMap(sheet);
      var uCol = map['username'] !== undefined ? map['username'] : 1;
      var tCol = map['token_session'] !== undefined ? map['token_session'] : 6;
      
      var target = (username || '').toString().trim().toLowerCase();
      var targetToken = (token || '').toString().trim();
      for (var i = 1; i < data.length; i++) {
        if (toSafeString(data[i][uCol]).toLowerCase() === target) {
          // Hanya sesi pada perangkat ini yang dikeluarkan, sesi lain tetap aktif
          var activeTokens = parseSessionTokens(data[i][tCol]);
          var remainingTokens = [];
          for (var j = 0; j < activeTokens.length; j++) {
            if (activeTokens[j] !== targetToken) remainingTokens.push(activeTokens[j]);
          }
          sheet.getRange(i + 1, tCol + 1).setValue(remainingTokens.join(','));
          SpreadsheetApp.flush();
          break;
        }
      }
    }
    logActivity(username || 'User', 'Logout', 'Pengguna telah keluar dari sistem.');
    return { success: true };
  } catch (e) {
    return { success: false, message: e.message };
  }
}

/**
 * Helper Sesi Login (Multi Session untuk Siswa)
 * ---------------------------------------------
 * Kolom token_session menyimpan satu atau beberapa token aktif yang dipisah koma.
 * Format lama (satu token) tetap kompatibel karena selalu dipecah menjadi daftar.
 */

/**
 * Memecah isi kolom token_session menjadi daftar token aktif (tanpa duplikat).
 * @param {*} rawValue - Isi sel token_session
 * @return {Array<string>}
 */
function parseSessionTokens(rawValue) {
  var raw = toSafeString(rawValue).trim();
  if (!raw) return [];
  var parts = raw.split(',');
  var tokens = [];
  for (var i = 0; i < parts.length; i++) {
    var t = parts[i].trim();
    if (t && tokens.indexOf(t) === -1) tokens.push(t);
  }
  return tokens;
}

/**
 * Membentuk daftar token sesi terbaru tanpa melampaui batas maksimum.
 * Token baru selalu diletakkan paling akhir; token terlama dibuang (dikeluarkan).
 * @param {Array<string>} existingTokens - Token aktif sebelumnya (urut: terlama dulu)
 * @param {string} newToken - Token hasil login baru
 * @param {number} maxSessions - Maksimum jumlah sesi bersamaan
 * @return {Array<string>}
 */
function buildSessionTokenList(existingTokens, newToken, maxSessions) {
  var list = [];
  for (var i = 0; i < (existingTokens || []).length; i++) {
    var t = existingTokens[i];
    if (t && t !== newToken && list.indexOf(t) === -1) list.push(t);
  }
  list.push(newToken);
  var max = parseInt(maxSessions, 10);
  if (isNaN(max) || max < 1) max = 1;
  if (list.length > max) list = list.slice(list.length - max);
  return list;
}

/**
 * Membaca batas maksimum sesi login bersamaan untuk siswa dari sheet Settings.
 * Nilai default1, minimal 1, maksimal 50.
 * @return {number}
 */
function getMaxStudentSessions() {
  try {
    var s = getSettings();
    var raw = (s.settings && s.settings.max_student_sessions !== undefined) ? s.settings.max_student_sessions : '1';
    var limit = parseInt(toSafeString(raw).trim(), 10);
    if (isNaN(limit) || limit < 1) limit = 1;
    if (limit > 50) limit = 50;
    return limit;
  } catch (e) {
    Logger.log('Gagal membaca pengaturan max_student_sessions: ' + e.message);
    return 1;
  }
}

/**
 * Membaca daftar browser yang diizinkan untuk login SISWA dari sheet Settings.
 * Nilai kosong berarti tidak ada pembatasan (semua browser diizinkan).
 * @return {string} Daftar browser dipisah koma (mis. "SEB, Chrome")
 */
function getAllowedStudentBrowsers() {
  try {
    var s = getSettings();
    var raw = (s.settings && s.settings.allowed_student_browsers !== undefined)
      ? s.settings.allowed_student_browsers
      : '';
    return toSafeString(raw).trim();
  } catch (e) {
    Logger.log('Gagal membaca pengaturan allowed_student_browsers: ' + e.message);
    return '';
  }
}

/**
 * Mendeteksi nama browser dari string User-Agent.
 * Urutan deteksi penting: SEB & Edge/Opera harus dicek sebelum Chrome karena
 * User-Agent mereka juga mengandung "Chrome/".
 * @param {string} userAgent
 * @return {string} seb|edge|opera|samsung|firefox|chrome|safari|ie ('' bila tidak dikenal)
 */
function detectUserAgentBrowser(userAgent) {
  var ua = toSafeString(userAgent).toLowerCase();
  if (!ua) return '';
  if (ua.indexOf('seb/') !== -1 || ua.indexOf('safexambrowser') !== -1 || ua.indexOf('safe exam browser') !== -1) return 'seb';
  if (ua.indexOf('edg/') !== -1 || ua.indexOf('edga/') !== -1 || ua.indexOf('edgios/') !== -1) return 'edge';
  if (ua.indexOf('opr/') !== -1 || ua.indexOf('opera') !== -1) return 'opera';
  if (ua.indexOf('samsungbrowser') !== -1) return 'samsung';
  if (ua.indexOf('firefox/') !== -1 || ua.indexOf('fxios/') !== -1) return 'firefox';
  if (ua.indexOf('chrome/') !== -1 || ua.indexOf('crios/') !== -1) return 'chrome';
  if (ua.indexOf('safari/') !== -1) return 'safari';
  if (ua.indexOf('msie') !== -1 || ua.indexOf('trident/') !== -1) return 'ie';
  return '';
}

/**
 * Memeriksa apakah browser yang dipakai siswa termasuk daftar yang diizinkan.
 * Daftar dipisah koma dan pencocokan tidak peka huruf besar/kecil.
 * Contoh: "SEB, Chrome" -> hanya Safe Exam Browser dan Google Chrome
 * (Edge/Opera/Samsung tidak ikut lolos walau User-Agent-nya mengandung "Chrome").
 * @param {string} userAgent - navigator.userAgent dari browser siswa
 * @param {string} allowedRaw - daftar browser yang diizinkan (dipisah koma)
 * @return {boolean}
 */
function isStudentBrowserAllowed(userAgent, allowedRaw) {
  var list = toSafeString(allowedRaw).split(',')
    .map(function(item) { return toSafeString(item).trim().toLowerCase(); })
    .filter(function(item) { return Boolean(item); });
  if (list.length === 0) return true; // tidak dibatasi
  
  var ua = toSafeString(userAgent).toLowerCase();
  if (!ua) return false; // browser tidak terdeteksi -> tolak
  
  var detected = detectUserAgentBrowser(ua);
  var aliases = {
    'chrome': 'chrome', 'google chrome': 'chrome', 'chromium': 'chrome',
    'edge': 'edge', 'microsoft edge': 'edge', 'ms edge': 'edge',
    'firefox': 'firefox', 'mozilla firefox': 'firefox',
    'safari': 'safari', 'apple safari': 'safari',
    'opera': 'opera', 'opera gx': 'opera',
    'seb': 'seb', 'safe exam browser': 'seb', 'safeexambrowser': 'seb',
    'samsung': 'samsung', 'samsung internet': 'samsung',
    'ie': 'ie', 'internet explorer': 'ie'
  };
  
  for (var i = 0; i < list.length; i++) {
    var token = list[i];
    if (aliases[token]) {
      if (detected && aliases[token] === detected) return true;
    } else if (ua.indexOf(token) !== -1) {
      // Nilai bebas, mis. "SEB/3.5", "CriOS", "HeadlessChrome"
      return true;
    }
  }
  return false;
}

/**
 * Menegakkan batas sesi login siswa: membuang token paling lama sehingga
 * setiap siswa hanya memiliki maksimal "limit" sesi aktif.
 * Dipanggil saat batas diubah dari halaman Pengaturan.
 * @param {number} limit - Batas maksimum sesi login siswa
 * @return {number} Jumlah sesi yang dikeluarkan
 */
function enforceStudentSessionLimit(limit) {
  try {
    limit = parseInt(limit, 10);
    if (isNaN(limit) || limit < 1) limit = 1;
    
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.USERS);
    if (!sheet) return 0;
    
    var map = getHeaderMap(sheet);
    var rCol = map['role'] !== undefined ? map['role'] : 4;
    var tCol = map['token_session'] !== undefined ? map['token_session'] : 6;
    
    var data = sheet.getDataRange().getValues();
    var trimmed = 0;
    for (var i = 1; i < data.length; i++) {
      if (toSafeString(data[i][rCol]).toLowerCase().trim() !== 'siswa') continue;
      var tokens = parseSessionTokens(data[i][tCol]);
      if (tokens.length <= limit) continue;
      var kept = tokens.slice(tokens.length - limit);
      sheet.getRange(i + 1, tCol + 1).setValue(kept.join(','));
      trimmed += (tokens.length - kept.length);
    }
    if (trimmed > 0) SpreadsheetApp.flush();
    return trimmed;
  } catch (e) {
    Logger.log('Gagal memberlakukan batas sesi login siswa: ' + e.message);
    return 0;
  }
}

/**
 * Mengambil pengaturan aplikasi (Profil Sekolah & Keamanan Anti-Cheat).
 * Dapat diakses publik untuk inisialisasi branding.
 */
function getSettings() {
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.SETTINGS);
    if (!sheet) {
      CreateDb();
      sheet = getSheetSafe(ss, SHEETS.SETTINGS);
    }
    
    var data = sheet.getDataRange().getValues();
    var settings = {
      nama_sekolah: 'SMA / SMK Digital Platform',
      logo_url: '',
      tahun_ajaran: '2026/2027',
      max_student_sessions: '1',
      allowed_student_browsers: '',
      wajib_fullscreen: 'Ya',
      deteksi_tab_switch: 'Ya',
      blokir_aksi: 'Ya',
      dynamic_watermark: 'Ya',
      gemini_api_key: '',
      gemini_model: 'gemini-2.0-flash'
    };
    
    for (var i = 1; i < data.length; i++) {
      var key = toSafeString(data[i][0]).toLowerCase().trim();
      var val = toSafeString(data[i][1]).trim();
      if (key) {
        settings[key] = val;
      }
    }
    
    return { success: true, settings: settings };
  } catch (err) {
    return {
      success: true,
      settings: {
        nama_sekolah: 'SMA / SMK Digital Platform',
        logo_url: '',
        tahun_ajaran: '2026/2027',
        max_student_sessions: '1',
        allowed_student_browsers: '',
        wajib_fullscreen: 'Ya',
        deteksi_tab_switch: 'Ya',
        blokir_aksi: 'Ya',
        dynamic_watermark: 'Ya',
        gemini_api_key: '',
        gemini_model: 'gemini-2.0-flash'
      }
    };
  }
}

/**
 * Menyimpan pembaruan pengaturan aplikasi oleh Admin
 */
function saveSettings(username, token, settingsData) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(10000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.SETTINGS);
    if (!sheet) {
      CreateDb();
      sheet = getSheetSafe(ss, SHEETS.SETTINGS);
    }
    
    var data = sheet.getDataRange().getValues();
    var keysInSheet = {};
    for (var i = 1; i < data.length; i++) {
      var k = toSafeString(data[i][0]).toLowerCase();
      if (k) keysInSheet[k] = i + 1; // baris 1-based
    }
    
    var descriptions = {
      nama_sekolah: 'Nama sekolah atau institusi penyelenggara',
      logo_url: 'URL gambar logo sekolah',
      tahun_ajaran: 'Tahun ajaran / semester aktif',
      max_student_sessions: 'Batas maksimal sesi login bersamaan untuk siswa (jumlah perangkat berbeda)',
      allowed_student_browsers: 'Daftar browser yang diizinkan untuk login siswa, dipisah koma (mis. SEB, Chrome). Kosong = semua browser diizinkan',
      wajib_fullscreen: 'Wajib mode layar penuh selama ujian (Ya/Tidak)',
      deteksi_tab_switch: 'Deteksi berpindah tab atau kehilangan fokus (Ya/Tidak)',
      blokir_aksi: 'Blokir klik kanan, copy, paste, dan pintasan keyboard (Ya/Tidak)',
      dynamic_watermark: 'Tampilkan watermark identitas siswa melayang di area soal (Ya/Tidak)',
      gemini_api_key: 'Google Gemini API Key untuk fitur generate soal AI',
      gemini_model: 'Model Gemini yang digunakan untuk generate konten'
    };
    
    var sessionLimitUpdated = false;
    for (var key in settingsData) {
      var normalizedKey = key.toLowerCase();
      var val = toSafeString(settingsData[key]);
      
      // Normalisasi batas maksimum sesi login siswa (harus angka bulat >= 1)
      if (normalizedKey === 'max_student_sessions') {
        var limitNum = parseInt(val, 10);
        if (isNaN(limitNum) || limitNum < 1) limitNum = 1;
        if (limitNum > 50) limitNum = 50;
        val = String(limitNum);
        sessionLimitUpdated = true;
      }
      
      if (keysInSheet[normalizedKey]) {
        sheet.getRange(keysInSheet[normalizedKey], 2).setValue(val);
      } else {
        var desc = descriptions[normalizedKey] || 'Pengaturan sistem';
        sheet.appendRow([normalizedKey, val, desc]);
      }
    }
    SpreadsheetApp.flush();
    
    // Penegakan batas: keluarkan sesi login siswa terlama bila melebihi batas baru
    var finalSessionLimit = getMaxStudentSessions();
    var trimmedSessions = 0;
    if (sessionLimitUpdated) {
      trimmedSessions = enforceStudentSessionLimit(finalSessionLimit);
    }
    
    logActivity(username, 'Update Settings', 'Pengaturan sistem sekolah & keamanan diperbarui.' +
      (sessionLimitUpdated ? ' Batas sesi login siswa: ' + finalSessionLimit + ' perangkat' + (trimmedSessions > 0 ? ' (' + trimmedSessions + ' sesi terlama dikeluarkan)' : '') + '.' : ''));
    return {
      success: true,
      message: 'Pengaturan berhasil disimpan!' + (trimmedSessions > 0 ? ' ' + trimmedSessions + ' sesi login siswa terlama telah dikeluarkan.' : '')
    };
  } catch (e) {
    return { success: false, message: 'Gagal menyimpan pengaturan: ' + e.message };
  } finally {
    lock.releaseLock();
  }
}

// ==========================================
// 5. MODUL ADMIN: PENGELOLAAN PENGGUNA & SISTEM
// ==========================================

/**
 * Mengambil daftar pengguna saja (ringan dan cepat)
 */
function getUsers(username, token) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }
  try {
    var ss = getSpreadsheet();
    var usersSheet = getSheetSafe(ss, SHEETS.USERS);
    if (!usersSheet) {
      return { success: false, message: 'Sheet Users tidak ditemukan.' };
    }
    
    var usersData = usersSheet.getDataRange().getValues();
    var map = getHeaderMap(usersSheet);
    var idIdx = map['id_user'] !== undefined ? map['id_user'] : 0;
    var uIdx = map['username'] !== undefined ? map['username'] : 1;
    var nIdx = map['nama_lengkap'] !== undefined ? map['nama_lengkap'] : 3;
    var rIdx = map['role'] !== undefined ? map['role'] : 4;
    var kIdx = map['kelas'] !== undefined ? map['kelas'] : 5;
    var sIdx = map['status_aktif'] !== undefined ? map['status_aktif'] : 7;
    var sesiIdx = map['sesi'] !== undefined ? map['sesi'] : -1;
    
    var users = [];
    for (var i = 1; i < usersData.length; i++) {
      var uName = toSafeString(usersData[i][uIdx]);
      if (!uName) continue; // lewati baris kosong
      
      users.push({
        id_user: toSafeString(usersData[i][idIdx]),
        username: uName,
        nama_lengkap: toSafeString(usersData[i][nIdx]),
        role: toSafeString(usersData[i][rIdx]) || 'Siswa',
        kelas: toSafeString(usersData[i][kIdx]) || '-',
        sesi: sesiIdx !== -1 ? (toSafeString(usersData[i][sesiIdx]) || '-') : '-',
        status_aktif: toSafeString(usersData[i][sIdx]) || 'Aktif'
      });
    }
    
    return { success: true, users: users };
  } catch (err) {
    return { success: false, message: 'Gagal memuat pengguna: ' + err.message };
  }
}

/**
 * Data siswa untuk cetak kartu ujian (termasuk password).
 * Hanya dapat diakses oleh Admin & Guru.
 * @returns {object} { success, students:[{username, password, nama_lengkap, kelas, sesi}] }
 */
function getStudentExamCards(username, token) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }

  try {
    var ss = getSpreadsheet();
    var usersSheet = getSheetSafe(ss, SHEETS.USERS);
    if (!usersSheet) {
      return { success: false, message: 'Sheet Users tidak ditemukan.' };
    }

    var data = usersSheet.getDataRange().getValues();
    var map = getHeaderMap(usersSheet);
    var uCol = map['username'] !== undefined ? map['username'] : 1;
    var pCol = map['password'] !== undefined ? map['password'] : 2;
    var nCol = map['nama_lengkap'] !== undefined ? map['nama_lengkap'] : 3;
    var rCol = map['role'] !== undefined ? map['role'] : 4;
    var kCol = map['kelas'] !== undefined ? map['kelas'] : 5;
    var sCol = map['status_aktif'] !== undefined ? map['status_aktif'] : 7;
    var sesiCol = map['sesi'] !== undefined ? map['sesi'] : -1;

    // Guard: hanya Admin & Guru
    var callerUname = (username || '').toString().trim().toLowerCase();
    var callerRole = '';
    for (var ci = 1; ci < data.length; ci++) {
      if (toSafeString(data[ci][uCol]).toLowerCase() === callerUname) {
        callerRole = toSafeString(data[ci][rCol]).toLowerCase().trim();
        break;
      }
    }
    if (callerRole !== 'admin' && callerRole !== 'guru') {
      return { success: false, message: 'Hanya Admin atau Guru yang dapat mencetak kartu ujian.' };
    }

    var students = [];
    var seen = {};
    for (var i = 1; i < data.length; i++) {
      if (toSafeString(data[i][rCol]).toLowerCase().trim() !== 'siswa') continue;
      if (toSafeString(data[i][sCol]).toLowerCase().trim() === 'nonaktif') continue;

      var uname = toSafeString(data[i][uCol]).trim();
      if (!uname) continue;
      var key = uname.toLowerCase();
      if (seen[key]) continue;
      seen[key] = true;

      students.push({
        username: uname,
        password: toSafeString(data[i][pCol]),
        nama_lengkap: toSafeString(data[i][nCol]) || uname,
        kelas: toSafeString(data[i][kCol]) || '-',
        sesi: sesiCol !== -1 ? (toSafeString(data[i][sesiCol]) || '-') : '-'
      });
    }

    students.sort(function(a, b) {
      if (a.kelas !== b.kelas) return a.kelas.localeCompare(b.kelas);
      return a.nama_lengkap.localeCompare(b.nama_lengkap);
    });

    return { success: true, students: students };
  } catch (e) {
    return { success: false, message: 'Gagal memuat data kartu ujian: ' + e.message };
  }
}

/**
 * Mengambil data komprehensif Admin (Stats, Users, Sessions, Logs)
 */
function getAdminData(username, token) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }
  
  try {
    var ss = getSpreadsheet();
    var usersSheet = getSheetSafe(ss, SHEETS.USERS);
    var examsSheet = getSheetSafe(ss, SHEETS.EXAMS);
    var resultsSheet = getSheetSafe(ss, SHEETS.RESULTS);
    var logsSheet = getSheetSafe(ss, SHEETS.LOGS);
    var sesiSheet = getSheetSafe(ss, SHEETS.SESI);
    
    if (!usersSheet) {
      return { success: false, message: 'Sheet Users belum siap. Jalankan CreateDb().' };
    }
    
    var usersData = usersSheet.getDataRange().getValues();
    var examsData = examsSheet ? examsSheet.getDataRange().getValues() : [];
    var resultsData = resultsSheet ? resultsSheet.getDataRange().getValues() : [];
    var logsData = logsSheet ? logsSheet.getDataRange().getValues() : [];
    var sesiData = sesiSheet ? sesiSheet.getDataRange().getValues() : [];
    
    // Header mappings
    var uMap = getHeaderMap(usersSheet);
    var idIdx = uMap['id_user'] !== undefined ? uMap['id_user'] : 0;
    var uIdx = uMap['username'] !== undefined ? uMap['username'] : 1;
    var nIdx = uMap['nama_lengkap'] !== undefined ? uMap['nama_lengkap'] : 3;
    var rIdx = uMap['role'] !== undefined ? uMap['role'] : 4;
    var kIdx = uMap['kelas'] !== undefined ? uMap['kelas'] : 5;
    var sIdx = uMap['status_aktif'] !== undefined ? uMap['status_aktif'] : 7;
    var sesiIdx = uMap['sesi'] !== undefined ? uMap['sesi'] : -1;
    var tktIdx = uMap['tingkat'] !== undefined ? uMap['tingkat'] : -1;
    
    var users = [];
    var stats = {
      totalStudents: 0,
      totalTeachers: 0,
      totalAdmins: 0,
      totalExams: examsData.length > 1 ? examsData.length - 1 : 0,
      totalSubmissions: 0
    };
    
    for (var i = 1; i < usersData.length; i++) {
      var uName = toSafeString(usersData[i][uIdx]);
      if (!uName) continue; // Lewati baris kosong
      
      var uRole = toSafeString(usersData[i][rIdx]) || 'Siswa';
      var uObj = {
        id_user: toSafeString(usersData[i][idIdx]),
        username: uName,
        nama_lengkap: toSafeString(usersData[i][nIdx]),
        role: uRole,
        kelas: toSafeString(usersData[i][kIdx]) || '-',
        sesi: sesiIdx !== -1 ? (toSafeString(usersData[i][sesiIdx]) || '-') : '-',
        tingkat: tktIdx !== -1 ? (toSafeString(usersData[i][tktIdx]) || '-') : '-',
        status_aktif: toSafeString(usersData[i][sIdx]) || 'Aktif'
      };
      users.push(uObj);
      
      var lowerRole = uRole.toLowerCase();
      if (lowerRole === 'siswa') stats.totalStudents++;
      else if (lowerRole === 'guru') stats.totalTeachers++;
      else if (lowerRole === 'admin') stats.totalAdmins++;
    }
    
    for (var j = 1; j < resultsData.length; j++) {
      var resStatus = toSafeString(resultsData[j][7]);
      if (resStatus === 'SUBMITTED') {
        stats.totalSubmissions++;
      }
    }
    
    // Master Sesi
    var sessions = [];
    if (sesiData.length > 1) {
      var sMap = getHeaderMap(sesiSheet);
      var sIdCol = sMap['id_sesi'] !== undefined ? sMap['id_sesi'] : 0;
      var sNameCol = sMap['nama_sesi'] !== undefined ? sMap['nama_sesi'] : 1;
      var sKetCol = sMap['keterangan'] !== undefined ? sMap['keterangan'] : 2;
      for (var si = 1; si < sesiData.length; si++) {
        var sId = toSafeString(sesiData[si][sIdCol]);
        var sName = toSafeString(sesiData[si][sNameCol]);
        if (!sId && !sName) continue;
        sessions.push({
          id_sesi: sId,
          nama_sesi: sName,
          keterangan: toSafeString(sesiData[si][sKetCol])
        });
      }
    }
    
    var logs = [];
    if (logsData.length > 1) {
      var maxLogs = Math.min(logsData.length - 1, 50);
      for (var k = logsData.length - 1; k >= logsData.length - maxLogs && k >= 1; k--) {
        logs.push({
          timestamp: toSafeString(logsData[k][0]),
          username: toSafeString(logsData[k][1]),
          jenis_kejadian: toSafeString(logsData[k][2]),
          keterangan: toSafeString(logsData[k][3])
        });
      }
    }

    // Daftar Seluruh Paket Ujian untuk Rekap & Manajemen Admin
    var exams = [];
    if (examsData.length > 1) {
      var eMap = getHeaderMap(examsSheet);
      var eIdCol = eMap['id_ujian'] !== undefined ? eMap['id_ujian'] : 0;
      var eNameCol = eMap['nama_ujian'] !== undefined ? eMap['nama_ujian'] : 1;
      var eDurCol = eMap['durasi_menit'] !== undefined ? eMap['durasi_menit'] : 2;
      var eStartCol = eMap['waktu_mulai'] !== undefined ? eMap['waktu_mulai'] : 3;
      var eEndCol = eMap['waktu_selesai'] !== undefined ? eMap['waktu_selesai'] : 4;
      var eAcakSoalCol = eMap['acak_soal'] !== undefined ? eMap['acak_soal'] : 5;
      var eAcakOpsiCol = eMap['acak_opsi'] !== undefined ? eMap['acak_opsi'] : 6;
      var eTokenCol = eMap['token_ujian'] !== undefined ? eMap['token_ujian'] : 7;
      var eGuruCol = eMap['id_guru'] !== undefined ? eMap['id_guru'] : 9;
      var eKelasCol = eMap['kelas'] !== undefined ? eMap['kelas'] : -1;
      var eSesiCol = eMap['sesi'] !== undefined ? eMap['sesi'] : -1;
      var eTipeCol = eMap['tipe_peserta'] !== undefined ? eMap['tipe_peserta'] : -1;
      var ePesertaCol = eMap['peserta_khusus'] !== undefined ? eMap['peserta_khusus'] : -1;
      var eKolabCol = eMap['kolaborator'] !== undefined ? eMap['kolaborator'] : -1;
      var eTktCol = eMap['tingkat'] !== undefined ? eMap['tingkat'] : -1;
      var eTampilCol = eMap['jumlah_soal_tampil'] !== undefined ? eMap['jumlah_soal_tampil'] : -1;
      var ePercobaanCol = eMap['jumlah_percobaan'] !== undefined ? eMap['jumlah_percobaan'] : -1;
      var eShowKeyCol = eMap['tampilkan_pembahasan'] !== undefined ? eMap['tampilkan_pembahasan'] : -1;
      var eMaxViolCol = eMap['max_violations'] !== undefined ? eMap['max_violations'] : -1;
      var eTindakanViolCol = eMap['tindakan_pelanggaran'] !== undefined ? eMap['tindakan_pelanggaran'] : -1;

      // Hitung jumlah soal per id_ujian
      var questionsSheet = getSheetSafe(ss, SHEETS.QUESTIONS);
      var questionsData = questionsSheet ? questionsSheet.getDataRange().getValues() : [];
      var qCounts = {};
      for (var qi = 1; qi < questionsData.length; qi++) {
        var qExamId = toSafeString(questionsData[qi][1]);
        if (qExamId) {
          qCounts[qExamId] = (qCounts[qExamId] || 0) + 1;
        }
      }

      // Hitung peserta submit per ujian (unik per siswa, bukan per percobaan)
      var submitCounts = {};
      var submitSeen = {};
      for (var jRes = 1; jRes < resultsData.length; jRes++) {
        var exId = toSafeString(resultsData[jRes][1]);
        var st = toSafeString(resultsData[jRes][7]);
        var stUser = toSafeString(resultsData[jRes][2]).trim().toLowerCase();
        if (st === 'SUBMITTED' && exId) {
          var stKey = exId + '|' + stUser;
          if (!submitSeen[stKey]) {
            submitSeen[stKey] = true;
            submitCounts[exId] = (submitCounts[exId] || 0) + 1;
          }
        }
      }

      for (var e = 1; e < examsData.length; e++) {
        var examId = toSafeString(examsData[e][eIdCol]);
        if (!examId) continue;
        exams.push({
          id_ujian: examId,
          nama_ujian: toSafeString(examsData[e][eNameCol]),
          durasi_menit: Number(examsData[e][eDurCol]) || 60,
          waktu_mulai: formatDateTimeIso(examsData[e][eStartCol]),
          waktu_selesai: formatDateTimeIso(examsData[e][eEndCol]),
          acak_soal: eAcakSoalCol !== -1 ? (toSafeString(examsData[e][eAcakSoalCol]) || 'Ya') : 'Ya',
          acak_opsi: eAcakOpsiCol !== -1 ? (toSafeString(examsData[e][eAcakOpsiCol]) || 'Ya') : 'Ya',
          token_ujian: eTokenCol !== -1 ? toSafeString(examsData[e][eTokenCol]).trim() : '',
          id_guru: toSafeString(examsData[e][eGuruCol]) || '-',
          kelas: eKelasCol !== -1 ? toSafeString(examsData[e][eKelasCol]) : '',
          sesi: eSesiCol !== -1 ? toSafeString(examsData[e][eSesiCol]) : '',
          tipe_peserta: eTipeCol !== -1 && toSafeString(examsData[e][eTipeCol]) ? toSafeString(examsData[e][eTipeCol]).trim().toLowerCase() : 'general',
          peserta_khusus: ePesertaCol !== -1 ? toSafeString(examsData[e][ePesertaCol]) : '',
          kolaborator: eKolabCol !== -1 ? toSafeString(examsData[e][eKolabCol]) : '',
          tingkat: eTktCol !== -1 ? toSafeString(examsData[e][eTktCol]) : '',
          jumlah_soal_tampil: eTampilCol !== -1 ? (parseInt(examsData[e][eTampilCol], 10) || 0) : 0,
          jumlah_percobaan: ePercobaanCol !== -1 ? normalizeExamAttempts(examsData[e][ePercobaanCol]) : 1,
          tampilkan_pembahasan: eShowKeyCol !== -1 && toSafeString(examsData[e][eShowKeyCol]).trim().toLowerCase() === 'ya' ? 'Ya' : 'Tidak',
          max_violations: eMaxViolCol !== -1 ? normalizeExamMaxViolations(examsData[e][eMaxViolCol]) : 3,
          tindakan_pelanggaran: eTindakanViolCol !== -1 ? normalizeViolationAction(examsData[e][eTindakanViolCol]) : 'auto_submit',
          jumlah_soal: qCounts[examId] || 0,
          jumlah_peserta: submitCounts[examId] || 0
        });
      }
    }

    // Siapkan distinctClasses, distinctGrades, classGradeMap, distinctSessions, distinctStudents, dan distinctTeachers untuk pembuatan ujian & target peserta
    var distinctClasses = [];
    var classSet = {};
    var distinctGrades = [];
    var classGradeMap = {};
    var distinctSessions = [];
    var distinctStudents = [];
    var distinctTeachers = [];

    for (var si = 0; si < sessions.length; si++) {
      var sName = sessions[si].nama_sesi;
      if (sName && distinctSessions.indexOf(sName) === -1) {
        distinctSessions.push(sName);
      }
    }

    for (var ui = 0; ui < users.length; ui++) {
      var u = users[ui];
      var kVal = (u.kelas || '').trim();
      var tVal = (u.tingkat || '').trim();
      if (kVal && kVal !== '-' && kVal.toLowerCase() !== 'semua' && !classSet[kVal]) {
        classSet[kVal] = true;
        distinctClasses.push(kVal);
      }
      if (tVal && tVal !== '-' && tVal.toLowerCase() !== 'semua' && distinctGrades.indexOf(tVal) === -1) {
        distinctGrades.push(tVal);
      }
      if (kVal && kVal !== '-' && tVal && tVal !== '-') {
        classGradeMap[kVal] = tVal;
      }
      var userSesi = (u.sesi || '').trim();
      if (userSesi && userSesi !== '-' && userSesi.toLowerCase() !== 'semua' && distinctSessions.indexOf(userSesi) === -1) {
        distinctSessions.push(userSesi);
      }
      var uRoleLower = (u.role || '').toLowerCase();
      var uStatusLower = (u.status_aktif || '').toLowerCase();
      if (uRoleLower === 'siswa' && uStatusLower !== 'nonaktif') {
        distinctStudents.push({
          username: u.username,
          nama_lengkap: u.nama_lengkap || u.username,
          kelas: u.kelas || '-',
          sesi: u.sesi || '-',
          tingkat: u.tingkat || '-'
        });
      }
      if ((uRoleLower === 'guru' || uRoleLower === 'admin') && uStatusLower !== 'nonaktif') {
        distinctTeachers.push({
          username: u.username,
          nama_lengkap: u.nama_lengkap || u.username,
          role: u.role || (uRoleLower === 'admin' ? 'Admin' : 'Guru')
        });
      }
    }
    distinctClasses.forEach(function(cls) {
      if (!classGradeMap[cls]) {
        var m = cls.match(/^(1[0-2]|[7-9]|X[IVX]*)/i);
        if (m) {
          var inf = m[1].toUpperCase();
          classGradeMap[cls] = inf;
          if (distinctGrades.indexOf(inf) === -1) distinctGrades.push(inf);
        }
      }
    });
    distinctClasses.sort();
    distinctGrades.sort(function(a, b) {
      var numA = parseInt(a, 10);
      var numB = parseInt(b, 10);
      if (!isNaN(numA) && !isNaN(numB)) return numA - numB;
      return a.localeCompare(b);
    });
    distinctSessions.sort();
    distinctStudents.sort(function(a, b) {
      if (a.kelas !== b.kelas) return a.kelas.localeCompare(b.kelas);
      return a.nama_lengkap.localeCompare(b.nama_lengkap);
    });
    distinctTeachers.sort(function(a, b) {
      var rA = (a.role || '').toLowerCase();
      var rB = (b.role || '').toLowerCase();
      if (rA !== rB) {
        if (rA === 'admin') return -1;
        if (rB === 'admin') return 1;
      }
      return a.nama_lengkap.localeCompare(b.nama_lengkap);
    });
    
    return {
      success: true,
      stats: stats,
      users: users,
      sessions: sessions,
      logs: logs,
      exams: exams,
      available_classes: distinctClasses,
      available_grades: distinctGrades,
      class_grade_map: classGradeMap,
      available_sessions: distinctSessions,
      available_students: distinctStudents,
      available_teachers: distinctTeachers
    };
  } catch (err) {
    return {
      success: false,
      message: 'Gagal memuat data admin: ' + err.message
    };
  }
}

function saveUser(username, token, userData) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(10000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.USERS);
    if (!sheet) {
      return { success: false, message: 'Sheet Users tidak ditemukan.' };
    }
    
    // Pastikan kolom sesi & tingkat ada
    var map = getHeaderMap(sheet);
    if (map['sesi'] === undefined || map['tingkat'] === undefined) {
      CreateDb();
      map = getHeaderMap(sheet);
    }
    
    var data = sheet.getDataRange().getValues();
    var idCol = map['id_user'] !== undefined ? map['id_user'] : 0;
    var uCol = map['username'] !== undefined ? map['username'] : 1;
    var pCol = map['password'] !== undefined ? map['password'] : 2;
    var nCol = map['nama_lengkap'] !== undefined ? map['nama_lengkap'] : 3;
    var rCol = map['role'] !== undefined ? map['role'] : 4;
    var kCol = map['kelas'] !== undefined ? map['kelas'] : 5;
    var tCol = map['token_session'] !== undefined ? map['token_session'] : 6;
    var sCol = map['status_aktif'] !== undefined ? map['status_aktif'] : 7;
    var sesiCol = map['sesi'] !== undefined ? map['sesi'] : 8;
    var tktCol = map['tingkat'] !== undefined ? map['tingkat'] : -1;
    
    var targetUsername = (userData.username || '').toString().trim().toLowerCase();
    if (!targetUsername) {
      return { success: false, message: 'Username tidak boleh kosong.' };
    }
    
    var isEdit = Boolean(userData.id_user);
    var existingRow = -1;
    
    for (var i = 1; i < data.length; i++) {
      var rowId = toSafeString(data[i][idCol]);
      var rowUsername = (data[i][uCol] || '').toString().trim().toLowerCase();
      
      if (isEdit && rowId === userData.id_user) {
        existingRow = i + 1;
      } else if (rowUsername === targetUsername) {
        return { success: false, message: 'Username sudah digunakan oleh akun lain.' };
      }
    }
    
    var sesiVal = toSafeString(userData.sesi || '-').trim() || '-';
    var tktVal = (userData.role || '').toLowerCase() === 'siswa' ? (toSafeString(userData.tingkat || '-').trim() || '-') : '-';
    var numCols = Math.max(sheet.getLastColumn(), SHEET_HEADERS.Users.length);
    
    if (isEdit) {
      if (existingRow === -1) {
        return { success: false, message: 'Data pengguna tidak ditemukan untuk diedit.' };
      }
      
      var rowData = data[existingRow - 1].slice();
      while (rowData.length < numCols) rowData.push('');
      
      var newPassword = userData.password ? userData.password.toString().trim() : rowData[pCol];
      rowData[uCol] = targetUsername;
      rowData[pCol] = newPassword;
      rowData[nCol] = userData.nama_lengkap || '';
      rowData[rCol] = userData.role || 'Siswa';
      rowData[kCol] = userData.kelas || '-';
      rowData[sCol] = userData.status_aktif || 'Aktif';
      if (sesiCol !== -1 && sesiCol < numCols) {
        rowData[sesiCol] = sesiVal;
      }
      if (tktCol !== -1 && tktCol < numCols) {
        rowData[tktCol] = tktVal;
      }
      
      sheet.getRange(existingRow, 1, 1, numCols).setValues([rowData]);
      SpreadsheetApp.flush();
      
      logActivity(username, 'Update User', 'Mengupdate user: ' + targetUsername + ' (Tingkat: ' + tktVal + ', Sesi: ' + sesiVal + ')');
      return { success: true, message: 'Pengguna berhasil diperbarui.' };
    } else {
      var newId = 'USR-' + Utilities.getUuid().substring(0, 8).toUpperCase();
      var pass = (userData.password || '123456').toString().trim();
      var newRow = new Array(numCols).fill('');
      
      newRow[idCol] = newId;
      newRow[uCol] = targetUsername;
      newRow[pCol] = pass;
      newRow[nCol] = userData.nama_lengkap || '';
      newRow[rCol] = userData.role || 'Siswa';
      newRow[kCol] = userData.kelas || '-';
      newRow[tCol] = '';
      newRow[sCol] = userData.status_aktif || 'Aktif';
      if (sesiCol !== -1 && sesiCol < numCols) {
        newRow[sesiCol] = sesiVal;
      }
      if (tktCol !== -1 && tktCol < numCols) {
        newRow[tktCol] = tktVal;
      }
      
      sheet.appendRow(newRow);
      SpreadsheetApp.flush();
      
      logActivity(username, 'Create User', 'Menambahkan user baru: ' + targetUsername + ' (' + userData.role + ', Tingkat: ' + tktVal + ', Sesi: ' + sesiVal + ')');
      return { success: true, message: 'Pengguna baru berhasil ditambahkan.' };
    }
  } catch (e) {
    return { success: false, message: 'Gagal menyimpan pengguna: ' + e.message };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Ubah password akun sendiri (dapat diakses oleh Admin, Guru, dan Siswa).
 * @param {string} username - Username aktif
 * @param {string} token - Token sesi
 * @param {string} currentPassword - Password lama
 * @param {string} newPassword - Password baru
 * @param {string} confirmPassword - Konfirmasi password baru
 */
function changePassword(username, token, currentPassword, newPassword, confirmPassword) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }

  currentPassword = (currentPassword || '').trim();
  newPassword = (newPassword || '').trim();
  confirmPassword = (confirmPassword || '').trim();

  if (!currentPassword) {
    return { success: false, message: 'Password saat ini wajib diisi.' };
  }
  if (!newPassword) {
    return { success: false, message: 'Password baru wajib diisi.' };
  }
  if (newPassword.length < 5) {
    return { success: false, message: 'Password baru minimal harus 5 karakter.' };
  }
  if (newPassword !== confirmPassword) {
    return { success: false, message: 'Konfirmasi password baru tidak cocok.' };
  }
  if (currentPassword === newPassword) {
    return { success: false, message: 'Password baru tidak boleh sama dengan password saat ini.' };
  }

  var lock = LockService.getScriptLock();
  lock.tryLock(10000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.USERS);
    if (!sheet) {
      return { success: false, message: 'Sheet Users tidak ditemukan.' };
    }

    var data = sheet.getDataRange().getValues();
    var map = getHeaderMap(sheet);
    var uCol = map['username'] !== undefined ? map['username'] : 1;
    var pCol = map['password'] !== undefined ? map['password'] : 2;

    var targetLower = username.toLowerCase().trim();
    var userRow = -1;

    for (var i = 1; i < data.length; i++) {
      if (toSafeString(data[i][uCol]).toLowerCase().trim() === targetLower) {
        var existingPass = toSafeString(data[i][pCol]);
        if (existingPass !== currentPassword) {
          return { success: false, message: 'Password saat ini salah. Silakan periksa kembali.' };
        }
        userRow = i + 1;
        break;
      }
    }

    if (userRow === -1) {
      return { success: false, message: 'Akun pengguna tidak ditemukan.' };
    }

    sheet.getRange(userRow, pCol + 1).setNumberFormat('@').setValue(newPassword);
    SpreadsheetApp.flush();

    logActivity(username, 'Change Password', 'Pengguna berhasil memperbarui password akun.');
    return { success: true, message: 'Password berhasil diperbarui!' };
  } catch (e) {
    return { success: false, message: 'Gagal memperbarui password: ' + e.message };
  } finally {
    lock.releaseLock();
  }
}

function deleteUser(username, token, id_user) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(10000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.USERS);
    if (!sheet) {
      return { success: false, message: 'Sheet Users tidak ditemukan.' };
    }
    var data = sheet.getDataRange().getValues();
    var map = getHeaderMap(sheet);
    var idCol = map['id_user'] !== undefined ? map['id_user'] : 0;
    var uCol = map['username'] !== undefined ? map['username'] : 1;
    
    for (var i = 1; i < data.length; i++) {
      if (toSafeString(data[i][idCol]) === id_user) {
        var deletedUsername = toSafeString(data[i][uCol]);
        if (deletedUsername.toLowerCase() === 'admin') {
          return { success: false, message: 'Akun Super Admin utama tidak boleh dihapus.' };
        }
        sheet.deleteRow(i + 1);
        SpreadsheetApp.flush();
        logActivity(username, 'Delete User', 'Menghapus user: ' + deletedUsername);
        return { success: true, message: 'Pengguna berhasil dihapus.' };
      }
    }
    return { success: false, message: 'Pengguna tidak ditemukan.' };
  } catch (e) {
    return { success: false, message: e.message };
  } finally {
    lock.releaseLock();
  }
}

// ==========================================
// 4b. MODUL SESI LOGIN SISWA (ADMIN & GURU)
// ==========================================

/**
 * Mengambil daftar seluruh siswa beserta status sesi login aktifnya.
 * Menyediakan informasi jumlah sesi aktif, batas maksimum, status online/offline,
 * login terakhir (dari sheet Logs), serta daftar kelas untuk keperluan filter.
 *
 * @param {string} username - Username aktif (Admin/Guru)
 * @param {string} token - Token sesi
 * @return {Object}
 */
function getStudentLoginSessions(username, token) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }

  try {
    var ss = getSpreadsheet();
    var usersSheet = getSheetSafe(ss, SHEETS.USERS);
    if (!usersSheet) {
      return { success: false, message: 'Sheet Users tidak ditemukan.' };
    }

    var map = getHeaderMap(usersSheet);
    var idCol = map['id_user'] !== undefined ? map['id_user'] : 0;
    var uCol = map['username'] !== undefined ? map['username'] : 1;
    var nCol = map['nama_lengkap'] !== undefined ? map['nama_lengkap'] : 3;
    var rCol = map['role'] !== undefined ? map['role'] : 4;
    var kCol = map['kelas'] !== undefined ? map['kelas'] : 5;
    var tCol = map['token_session'] !== undefined ? map['token_session'] : 6;
    var sCol = map['status_aktif'] !== undefined ? map['status_aktif'] : 7;
    var sesiCol = map['sesi'] !== undefined ? map['sesi'] : 8;
    var tktCol = map['tingkat'] !== undefined ? map['tingkat'] : -1;

    var data = usersSheet.getDataRange().getValues();
    var maxSessions = getMaxStudentSessions();

    // Hanya Admin & Guru yang boleh mengakses daftar sesi login siswa
    var callerRole = '';
    var callerUname = (username || '').toString().trim().toLowerCase();
    for (var ci = 1; ci < data.length; ci++) {
      if (toSafeString(data[ci][uCol]).toLowerCase() === callerUname) {
        callerRole = toSafeString(data[ci][rCol]).toLowerCase().trim();
        break;
      }
    }
    if (callerRole !== 'admin' && callerRole !== 'guru') {
      return { success: false, message: 'Hanya Admin atau Guru yang dapat mengakses menu Login Siswa.' };
    }

    // Login terakhir tiap siswa: sheet Logs ditambahkan secara kronologis,
    // sehingga pindai dari baris terakhir ke atas (pertama = yang terbaru).
    var lastLogins = {};
    try {
      var logsSheet = getSheetSafe(ss, SHEETS.LOGS);
      if (logsSheet) {
        var logsData = logsSheet.getDataRange().getValues();
        var logMap = getHeaderMap(logsSheet);
        var lUCol = logMap['username'] !== undefined ? logMap['username'] : 1;
        var lJCol = logMap['jenis_kejadian'] !== undefined ? logMap['jenis_kejadian'] : 2;
        var lTCol = logMap['timestamp'] !== undefined ? logMap['timestamp'] : 0;

        for (var li = logsData.length - 1; li >= 1; li--) {
          if (toSafeString(logsData[li][lJCol]).toLowerCase().trim() !== 'login') continue;
          var logUser = toSafeString(logsData[li][lUCol]).toLowerCase();
          if (logUser && lastLogins[logUser] === undefined) {
            lastLogins[logUser] = toSafeString(logsData[li][lTCol]);
          }
        }
      }
    } catch (logErr) {
      Logger.log('Gagal membaca riwayat login terakhir: ' + logErr.message);
    }

    var students = [];
    var classMap = {};
    var totalOnline = 0;

    for (var i = 1; i < data.length; i++) {
      var row = data[i];
      if (toSafeString(row[rCol]).toLowerCase().trim() !== 'siswa') continue;

      var uname = toSafeString(row[uCol]);
      if (!uname) continue;

      var activeTokens = parseSessionTokens(row[tCol]);
      var kelas = toSafeString(row[kCol]) || '-';
      if (kelas && kelas !== '-') classMap[kelas] = true;

      if (activeTokens.length > 0) totalOnline++;

      students.push({
        id_user: toSafeString(row[idCol]),
        username: uname,
        nama_lengkap: toSafeString(row[nCol]),
        kelas: kelas,
        sesi: sesiCol !== -1 ? (toSafeString(row[sesiCol]) || '-') : '-',
        tingkat: tktCol !== -1 ? (toSafeString(row[tktCol]) || '-') : '-',
        status_aktif: toSafeString(row[sCol]) || 'Aktif',
        active_sessions: activeTokens.length,
        max_sessions: maxSessions,
        is_online: activeTokens.length > 0,
        last_login: lastLogins[uname.toLowerCase()] || ''
      });
    }

    students.sort(function(a, b) {
      var an = (a.nama_lengkap || '').toLowerCase();
      var bn = (b.nama_lengkap || '').toLowerCase();
      if (an === bn) return (a.username || '').toLowerCase() < (b.username || '').toLowerCase() ? -1 : 1;
      return an < bn ? -1 : 1;
    });

    var classes = [];
    for (var c in classMap) {
      if (classMap.hasOwnProperty(c)) classes.push(c);
    }
    classes.sort();

    return {
      success: true,
      students: students,
      classes: classes,
      max_sessions: maxSessions,
      total_online: totalOnline,
      total_students: students.length
    };
  } catch (e) {
    return { success: false, message: 'Gagal memuat sesi login siswa: ' + e.message };
  }
}

/**
 * Mereset (mengeluarkan) seluruh sesi login aktif milik seorang siswa.
 * Siswa akan keluar dari semua perangkat dan wajib login kembali.
 *
 * @param {string} username - Username aktif pelaku reset (Admin/Guru)
 * @param {string} token - Token sesi
 * @param {string} targetUsername - Username siswa yang sesinya direset
 * @return {Object}
 */
function resetStudentLoginSession(username, token, targetUsername) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }

  var lock = LockService.getScriptLock();
  lock.tryLock(10000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.USERS);
    if (!sheet) {
      return { success: false, message: 'Sheet Users tidak ditemukan.' };
    }

    var data = sheet.getDataRange().getValues();
    var map = getHeaderMap(sheet);
    var uCol = map['username'] !== undefined ? map['username'] : 1;
    var nCol = map['nama_lengkap'] !== undefined ? map['nama_lengkap'] : 3;
    var rCol = map['role'] !== undefined ? map['role'] : 4;
    var tCol = map['token_session'] !== undefined ? map['token_session'] : 6;

    var target = (targetUsername || '').toString().trim().toLowerCase();
    if (!target) {
      return { success: false, message: 'Username siswa tidak boleh kosong.' };
    }

    // Hanya Admin & Guru yang boleh mereset sesi login siswa
    var callerRole = '';
    var callerUname = (username || '').toString().trim().toLowerCase();
    for (var ci = 1; ci < data.length; ci++) {
      if (toSafeString(data[ci][uCol]).toLowerCase() === callerUname) {
        callerRole = toSafeString(data[ci][rCol]).toLowerCase().trim();
        break;
      }
    }
    if (callerRole !== 'admin' && callerRole !== 'guru') {
      return { success: false, message: 'Hanya Admin atau Guru yang dapat mereset sesi login siswa.' };
    }

    for (var i = 1; i < data.length; i++) {
      if (toSafeString(data[i][uCol]).toLowerCase() !== target) continue;

      if (toSafeString(data[i][rCol]).toLowerCase().trim() !== 'siswa') {
        return { success: false, message: 'Reset sesi login hanya dapat dilakukan untuk akun siswa.' };
      }

      var targetName = toSafeString(data[i][nCol]) || target;
      var activeCount = parseSessionTokens(data[i][tCol]).length;

      if (activeCount === 0) {
        logActivity(username, 'Reset Login Siswa', 'Tidak ada sesi login aktif pada siswa: ' + target);
        return { success: true, message: 'Tidak ada sesi login aktif pada akun ' + targetName + '.' };
      }

      sheet.getRange(i + 1, tCol + 1).setValue('');
      SpreadsheetApp.flush();

      logActivity(username, 'Reset Login Siswa', 'Mengeluarkan ' + activeCount + ' sesi login siswa: ' + target + ' (' + targetName + ')');
      return {
        success: true,
        message: 'Sesi login ' + targetName + ' berhasil direset. ' + activeCount + ' perangkat telah dikeluarkan.'
      };
    }

    return { success: false, message: 'Siswa tidak ditemukan.' };
  } catch (e) {
    return { success: false, message: 'Gagal mereset sesi login: ' + e.message };
  } finally {
    lock.releaseLock();
  }
}

/**
 * MASTER DATA SESI UJIAN: CRUD
 */
function getSessions(username, token) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.SESI);
    if (!sheet) {
      CreateDb();
      sheet = getSheetSafe(ss, SHEETS.SESI);
    }
    var data = sheet.getDataRange().getValues();
    var map = getHeaderMap(sheet);
    var idCol = map['id_sesi'] !== undefined ? map['id_sesi'] : 0;
    var nameCol = map['nama_sesi'] !== undefined ? map['nama_sesi'] : 1;
    var ketCol = map['keterangan'] !== undefined ? map['keterangan'] : 2;
    
    var sessions = [];
    for (var i = 1; i < data.length; i++) {
      var sId = toSafeString(data[i][idCol]);
      var sName = toSafeString(data[i][nameCol]);
      if (!sId && !sName) continue;
      sessions.push({
        id_sesi: sId,
        nama_sesi: sName,
        keterangan: toSafeString(data[i][ketCol])
      });
    }
    return { success: true, sessions: sessions };
  } catch (err) {
    return { success: false, message: 'Gagal memuat data sesi: ' + err.message };
  }
}

function saveSession(username, token, sessionData) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(10000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.SESI);
    if (!sheet) {
      CreateDb();
      sheet = getSheetSafe(ss, SHEETS.SESI);
    }
    
    var namaVal = toSafeString(sessionData.nama_sesi).trim();
    if (!namaVal) {
      return { success: false, message: 'Nama sesi tidak boleh kosong.' };
    }
    var ketVal = toSafeString(sessionData.keterangan).trim();
    
    var data = sheet.getDataRange().getValues();
    var map = getHeaderMap(sheet);
    var idCol = map['id_sesi'] !== undefined ? map['id_sesi'] : 0;
    var nameCol = map['nama_sesi'] !== undefined ? map['nama_sesi'] : 1;
    var ketCol = map['keterangan'] !== undefined ? map['keterangan'] : 2;
    
    var isEdit = Boolean(sessionData.id_sesi);
    var targetRow = -1;
    
    if (isEdit) {
      for (var i = 1; i < data.length; i++) {
        if (toSafeString(data[i][idCol]) === sessionData.id_sesi) {
          targetRow = i + 1;
          break;
        }
      }
      if (targetRow === -1) {
        return { success: false, message: 'Data sesi tidak ditemukan untuk diedit.' };
      }
      
      sheet.getRange(targetRow, nameCol + 1).setValue(namaVal);
      sheet.getRange(targetRow, ketCol + 1).setValue(ketVal);
      SpreadsheetApp.flush();
      
      logActivity(username, 'Update Session', 'Mengupdate sesi: ' + namaVal);
      return { success: true, message: 'Sesi berhasil diperbarui.' };
    } else {
      var newSessionId = 'SES-' + Utilities.getUuid().substring(0, 8).toUpperCase();
      var numCols = Math.max(sheet.getLastColumn(), SHEET_HEADERS.Sesi.length);
      var newRow = new Array(numCols).fill('');
      newRow[idCol] = newSessionId;
      newRow[nameCol] = namaVal;
      newRow[ketCol] = ketVal;
      
      sheet.appendRow(newRow);
      SpreadsheetApp.flush();
      
      logActivity(username, 'Create Session', 'Menambahkan sesi baru: ' + namaVal);
      return { success: true, message: 'Sesi baru berhasil ditambahkan.', id_sesi: newSessionId };
    }
  } catch (e) {
    return { success: false, message: 'Gagal menyimpan sesi: ' + e.message };
  } finally {
    lock.releaseLock();
  }
}

function deleteSession(username, token, id_sesi) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(10000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.SESI);
    if (!sheet) {
      return { success: false, message: 'Sheet Sesi tidak ditemukan.' };
    }
    
    var data = sheet.getDataRange().getValues();
    var map = getHeaderMap(sheet);
    var idCol = map['id_sesi'] !== undefined ? map['id_sesi'] : 0;
    var nameCol = map['nama_sesi'] !== undefined ? map['nama_sesi'] : 1;
    var targetRow = -1;
    var sessionName = '';
    
    for (var i = 1; i < data.length; i++) {
      if (toSafeString(data[i][idCol]) === id_sesi) {
        targetRow = i + 1;
        sessionName = toSafeString(data[i][nameCol]);
        break;
      }
    }
    
    if (targetRow === -1) {
      return { success: false, message: 'Sesi tidak ditemukan untuk dihapus.' };
    }
    
    sheet.deleteRow(targetRow);
    SpreadsheetApp.flush();
    
    logActivity(username, 'Delete Session', 'Menghapus sesi: ' + (sessionName || id_sesi));
    return { success: true, message: 'Sesi berhasil dihapus.' };
  } catch (err) {
    return { success: false, message: 'Gagal menghapus sesi: ' + err.message };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Mengimpor daftar pengguna secara massal (Batch Import)
 * @param {string} username - Username admin
 * @param {string} token - Token sesi
 * @param {Array<Object>} usersList - Array objek [{username, password, nama_lengkap, role, kelas, sesi, status_aktif}]
 */
function importUsers(username, token, usersList) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }
  
  if (!usersList || !Array.isArray(usersList) || usersList.length === 0) {
    return { success: false, message: 'Data pengguna yang akan diimpor kosong.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(30000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.USERS);
    if (!sheet) {
      return { success: false, message: 'Sheet Users tidak ditemukan. Jalankan CreateDb() terlebih dahulu.' };
    }
    
    // Pastikan kolom sesi & tingkat ada di header
    var map = getHeaderMap(sheet);
    if (map['sesi'] === undefined || map['tingkat'] === undefined) {
      CreateDb();
      map = getHeaderMap(sheet);
    }
    
    var data = sheet.getDataRange().getValues();
    var idIdx = map['id_user'] !== undefined ? map['id_user'] : 0;
    var uIdx = map['username'] !== undefined ? map['username'] : 1;
    var pIdx = map['password'] !== undefined ? map['password'] : 2;
    var nIdx = map['nama_lengkap'] !== undefined ? map['nama_lengkap'] : 3;
    var rIdx = map['role'] !== undefined ? map['role'] : 4;
    var kIdx = map['kelas'] !== undefined ? map['kelas'] : 5;
    var tIdx = map['token_session'] !== undefined ? map['token_session'] : 6;
    var sIdx = map['status_aktif'] !== undefined ? map['status_aktif'] : 7;
    var sesiIdx = map['sesi'] !== undefined ? map['sesi'] : 8;
    var tktIdx = map['tingkat'] !== undefined ? map['tingkat'] : -1;
    
    var existingUsers = {};
    for (var i = 1; i < data.length; i++) {
      var existU = toSafeString(data[i][uIdx]).toLowerCase().trim();
      if (existU) {
        existingUsers[existU] = i + 1;
      }
    }
    
    var addedCount = 0;
    var updatedCount = 0;
    var skippedCount = 0;
    var numCols = Math.max(sheet.getLastColumn(), SHEET_HEADERS.Users.length);
    var rowsToAppend = [];
    
    for (var j = 0; j < usersList.length; j++) {
      var item = usersList[j];
      var rawUsername = toSafeString(item.username).trim();
      if (!rawUsername) {
        skippedCount++;
        continue;
      }
      
      var targetUsername = rawUsername.toLowerCase();
      var namaLengkap = toSafeString(item.nama_lengkap || item.nama).trim() || rawUsername;
      var rawRole = toSafeString(item.role || item.peran).trim();
      var role = 'Siswa';
      if (rawRole.toLowerCase() === 'guru') role = 'Guru';
      else if (rawRole.toLowerCase() === 'admin') role = 'Admin';
      
      var tingkat = toSafeString(item.tingkat || item.tingkat_kelas || item.level || item.grade).trim() || '-';
      var kelas = toSafeString(item.kelas || item.rombel).trim() || '-';
      var sesi = toSafeString(item.sesi || item.session || item.sesi_ujian).trim() || '-';
      var password = toSafeString(item.password).trim() || '123456';
      var status = toSafeString(item.status_aktif || item.status).trim() || 'Aktif';
      var finalTingkat = role === 'Siswa' ? tingkat : '-';
      
      if (existingUsers[targetUsername]) {
        var rowNum = existingUsers[targetUsername];
        var existingRowData = data[rowNum - 1].slice();
        while (existingRowData.length < numCols) existingRowData.push('');
        
        var finalPass = password || existingRowData[pIdx];
        existingRowData[uIdx] = rawUsername;
        existingRowData[pIdx] = finalPass;
        existingRowData[nIdx] = namaLengkap;
        existingRowData[rIdx] = role;
        existingRowData[kIdx] = kelas;
        existingRowData[sIdx] = status;
        if (sesiIdx !== -1 && sesiIdx < numCols) {
          existingRowData[sesiIdx] = sesi;
        }
        if (tktIdx !== -1 && tktIdx < numCols) {
          existingRowData[tktIdx] = finalTingkat;
        }
        
        sheet.getRange(rowNum, 1, 1, numCols).setValues([existingRowData]);
        updatedCount++;
      } else {
        var newId = 'USR-' + Utilities.getUuid().substring(0, 8).toUpperCase();
        var newRow = new Array(numCols).fill('');
        newRow[idIdx] = newId;
        newRow[uIdx] = rawUsername;
        newRow[pIdx] = password;
        newRow[nIdx] = namaLengkap;
        newRow[rIdx] = role;
        newRow[kIdx] = kelas;
        newRow[tIdx] = '';
        newRow[sIdx] = status;
        if (sesiIdx !== -1 && sesiIdx < numCols) {
          newRow[sesiIdx] = sesi;
        }
        if (tktIdx !== -1 && tktIdx < numCols) {
          newRow[tktIdx] = finalTingkat;
        }
        rowsToAppend.push(newRow);
        existingUsers[targetUsername] = true;
        addedCount++;
      }
    }
    
    if (rowsToAppend.length > 0) {
      var lastRow = sheet.getLastRow();
      sheet.getRange(lastRow + 1, 1, rowsToAppend.length, numCols).setValues(rowsToAppend);
    }
    
    SpreadsheetApp.flush();
    logActivity(username, 'Import Users', 'Mengimpor pengguna: ' + addedCount + ' baru, ' + updatedCount + ' diperbarui.');
    
    return {
      success: true,
      message: 'Import berhasil! ' + addedCount + ' pengguna baru ditambahkan, ' + updatedCount + ' diperbarui' + (skippedCount > 0 ? ', ' + skippedCount + ' baris dilewati (username kosong).' : '.'),
      added: addedCount,
      updated: updatedCount,
      skipped: skippedCount
    };
  } catch (err) {
    return { success: false, message: 'Gagal mengimpor data pengguna: ' + err.message };
  } finally {
    lock.releaseLock();
  }
}

// ==========================================
// 6. MODUL GURU: PENGELOLAAN UJIAN & BANK SOAL
// ==========================================

function getTeacherExams(username, token) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid.' };
  }
  
  try {
    var ss = getSpreadsheet();
    var examsSheet = getSheetSafe(ss, SHEETS.EXAMS);
    var questionsSheet = getSheetSafe(ss, SHEETS.QUESTIONS);
    var resultsSheet = getSheetSafe(ss, SHEETS.RESULTS);
    
    if (!examsSheet) {
      return { success: true, exams: [] };
    }
    
    var examsData = examsSheet.getDataRange().getValues();
    var questionsData = questionsSheet ? questionsSheet.getDataRange().getValues() : [];
    var resultsData = resultsSheet ? resultsSheet.getDataRange().getValues() : [];
    
    // Header map untuk Exams
    var map = getHeaderMap(examsSheet);
    var idCol = map['id_ujian'] !== undefined ? map['id_ujian'] : 0;
    var namaCol = map['nama_ujian'] !== undefined ? map['nama_ujian'] : 1;
    var durasiCol = map['durasi_menit'] !== undefined ? map['durasi_menit'] : 2;
    var mulaiCol = map['waktu_mulai'] !== undefined ? map['waktu_mulai'] : 3;
    var selesaiCol = map['waktu_selesai'] !== undefined ? map['waktu_selesai'] : 4;
    var acakSoalCol = map['acak_soal'] !== undefined ? map['acak_soal'] : 5;
    var acakOpsiCol = map['acak_opsi'] !== undefined ? map['acak_opsi'] : 6;
    var tokenCol = map['token_ujian'] !== undefined ? map['token_ujian'] : 7;
    var kelasCol = map['kelas'] !== undefined ? map['kelas'] : -1;
    var sesiCol = map['sesi'] !== undefined ? map['sesi'] : -1;
    var tipeCol = map['tipe_peserta'] !== undefined ? map['tipe_peserta'] : -1;
    var pesertaCol = map['peserta_khusus'] !== undefined ? map['peserta_khusus'] : -1;
    var guruCol = map['id_guru'] !== undefined ? map['id_guru'] : 9;
    var kolabCol = map['kolaborator'] !== undefined ? map['kolaborator'] : -1;
    var tktCol = map['tingkat'] !== undefined ? map['tingkat'] : -1;
    var tampilCol = map['jumlah_soal_tampil'] !== undefined ? map['jumlah_soal_tampil'] : -1;
    var pembahasanShowCol = map['tampilkan_pembahasan'] !== undefined ? map['tampilkan_pembahasan'] : -1;
    var percobaanCol = map['jumlah_percobaan'] !== undefined ? map['jumlah_percobaan'] : -1;
    var maxViolCol = map['max_violations'] !== undefined ? map['max_violations'] : -1;
    var tindakanViolCol = map['tindakan_pelanggaran'] !== undefined ? map['tindakan_pelanggaran'] : -1;
    
    // Hitung jumlah soal per id_ujian
    var qCounts = {};
    for (var i = 1; i < questionsData.length; i++) {
      var examId = toSafeString(questionsData[i][1]);
      if (examId) {
        qCounts[examId] = (qCounts[examId] || 0) + 1;
      }
    }
    
    // Hitung jumlah peserta yang telah submit (unik per siswa, bukan per percobaan)
    var submitCounts = {};
    var submitSeen = {};
    for (var j = 1; j < resultsData.length; j++) {
      var eId = toSafeString(resultsData[j][1]);
      var status = toSafeString(resultsData[j][7]);
      var sUser = toSafeString(resultsData[j][2]).trim().toLowerCase();
      if (status === 'SUBMITTED' && eId) {
        var sKey = eId + '|' + sUser;
        if (!submitSeen[sKey]) {
          submitSeen[sKey] = true;
          submitCounts[eId] = (submitCounts[eId] || 0) + 1;
        }
      }
    }
    
    // Dapatkan role pemanggil untuk filter hak akses (Admin melihat semua, Guru melihat miliknya & kolaborasinya)
    var callerRole = '';
    var usersSheet = getSheetSafe(ss, SHEETS.USERS);
    var uDataStd = usersSheet ? usersSheet.getDataRange().getValues() : [];
    var uMapStd = usersSheet ? getHeaderMap(usersSheet) : {};
    var uUnameCol = uMapStd['username'] !== undefined ? uMapStd['username'] : 1;
    var uNamaCol = uMapStd['nama_lengkap'] !== undefined ? uMapStd['nama_lengkap'] : 3;
    var uRoleCol = uMapStd['role'] !== undefined ? uMapStd['role'] : 4;
    var uStdKelasCol = uMapStd['kelas'] !== undefined ? uMapStd['kelas'] : 5;
    var uStdSesiCol = uMapStd['sesi'] !== undefined ? uMapStd['sesi'] : -1;
    var uStdTktCol = uMapStd['tingkat'] !== undefined ? uMapStd['tingkat'] : -1;
    var uStatusCol = uMapStd['status_aktif'] !== undefined ? uMapStd['status_aktif'] : 7;

    for (var us0 = 1; us0 < uDataStd.length; us0++) {
      if (toSafeString(uDataStd[us0][uUnameCol]).toLowerCase() === username.toLowerCase()) {
        callerRole = toSafeString(uDataStd[us0][uRoleCol]).toLowerCase();
        break;
      }
    }

    var list = [];
    for (var k = 1; k < examsData.length; k++) {
      var row = examsData[k];
      var rawId = toSafeString(row[idCol]);
      if (!rawId) continue;
      
      var owner = toSafeString(row[guruCol]).trim();
      var rawKolab = kolabCol !== -1 ? toSafeString(row[kolabCol]).trim() : '';
      var kolabList = rawKolab ? rawKolab.split(',').map(function(s) { return s.trim().toLowerCase(); }).filter(Boolean) : [];

      // Jika role adalah guru (bukan admin), hanya tampilkan ujian yang diampu sendiri atau di mana guru ini adalah kolaborator
      if (callerRole === 'guru') {
        var isOwner = (owner.toLowerCase() === username.toLowerCase()) || (!owner);
        var isKolab = kolabList.indexOf(username.toLowerCase()) !== -1;
        if (!isOwner && !isKolab) {
          continue;
        }
      }

      list.push({
        id_ujian: rawId,
        nama_ujian: toSafeString(row[namaCol]),
        durasi_menit: Number(row[durasiCol]) || 60,
        waktu_mulai: formatDateTimeIso(row[mulaiCol]),
        waktu_selesai: formatDateTimeIso(row[selesaiCol]),
        acak_soal: toSafeString(row[acakSoalCol]) || 'Ya',
        acak_opsi: toSafeString(row[acakOpsiCol]) || 'Ya',
        token_ujian: toSafeString(row[tokenCol]),
        kelas: kelasCol !== -1 ? toSafeString(row[kelasCol]) : '',
        sesi: sesiCol !== -1 ? toSafeString(row[sesiCol]) : '',
        tipe_peserta: tipeCol !== -1 && toSafeString(row[tipeCol]) ? toSafeString(row[tipeCol]).trim().toLowerCase() : 'general',
        peserta_khusus: pesertaCol !== -1 ? toSafeString(row[pesertaCol]) : '',
        id_guru: owner || username,
        kolaborator: rawKolab,
        tingkat: tktCol !== -1 ? toSafeString(row[tktCol]) : '',
        jumlah_soal_tampil: tampilCol !== -1 ? (parseInt(row[tampilCol], 10) || 0) : 0,
        jumlah_percobaan: percobaanCol !== -1 ? normalizeExamAttempts(row[percobaanCol]) : 1,
        tampilkan_pembahasan: pembahasanShowCol !== -1 && toSafeString(row[pembahasanShowCol]).trim().toLowerCase() === 'ya' ? 'Ya' : 'Tidak',
        max_violations: maxViolCol !== -1 ? normalizeExamMaxViolations(row[maxViolCol]) : 3,
        tindakan_pelanggaran: tindakanViolCol !== -1 ? normalizeViolationAction(row[tindakanViolCol]) : 'auto_submit',
        jumlah_soal: qCounts[rawId] || 0,
        jumlah_peserta: submitCounts[rawId] || 0
      });
    }
    
    // Ambil daftar kelas unik, tingkat, dan pemetaannya dari sheet Users
    var distinctClasses = [];
    var distinctGrades = [];
    var classGradeMap = {};
    if (usersSheet) {
      var uData = usersSheet.getDataRange().getValues();
      var uMap = getHeaderMap(usersSheet);
      var uKelasCol = uMap['kelas'] !== undefined ? uMap['kelas'] : 5;
      var uTktColLocal = uMap['tingkat'] !== undefined ? uMap['tingkat'] : -1;
      var classSet = {};
      for (var u = 1; u < uData.length; u++) {
        var kVal = toSafeString(uData[u][uKelasCol]).trim();
        var tVal = uTktColLocal !== -1 ? toSafeString(uData[u][uTktColLocal]).trim() : '';
        if (kVal && kVal !== '-' && kVal.toLowerCase() !== 'semua' && !classSet[kVal]) {
          classSet[kVal] = true;
          distinctClasses.push(kVal);
        }
        if (tVal && tVal !== '-' && tVal.toLowerCase() !== 'semua' && distinctGrades.indexOf(tVal) === -1) {
          distinctGrades.push(tVal);
        }
        if (kVal && kVal !== '-' && tVal && tVal !== '-') {
          classGradeMap[kVal] = tVal;
        }
      }
      distinctClasses.sort();
      distinctClasses.forEach(function(cls) {
        if (!classGradeMap[cls]) {
          var m = cls.match(/^(1[0-2]|[7-9]|X[IVX]*)/i);
          if (m) {
            var inf = m[1].toUpperCase();
            classGradeMap[cls] = inf;
            if (distinctGrades.indexOf(inf) === -1) distinctGrades.push(inf);
          }
        }
      });
      distinctGrades.sort(function(a, b) {
        var numA = parseInt(a, 10);
        var numB = parseInt(b, 10);
        if (!isNaN(numA) && !isNaN(numB)) return numA - numB;
        return a.localeCompare(b);
      });
    }

    // Ambil daftar sesi dari Master Sesi dan Users
    var distinctSessions = [];
    var sesiSheet = getSheetSafe(ss, SHEETS.SESI);
    if (sesiSheet) {
      var sData = sesiSheet.getDataRange().getValues();
      var sMap = getHeaderMap(sesiSheet);
      var sNameCol = sMap['nama_sesi'] !== undefined ? sMap['nama_sesi'] : 1;
      for (var si = 1; si < sData.length; si++) {
        var sVal = toSafeString(sData[si][sNameCol]).trim();
        if (sVal && distinctSessions.indexOf(sVal) === -1) {
          distinctSessions.push(sVal);
        }
      }
    }
    if (usersSheet) {
      var uData2 = usersSheet.getDataRange().getValues();
      var uMap2 = getHeaderMap(usersSheet);
      var uSesiCol = uMap2['sesi'] !== undefined ? uMap2['sesi'] : -1;
      if (uSesiCol !== -1) {
        for (var u2 = 1; u2 < uData2.length; u2++) {
          var uSesiVal = toSafeString(uData2[u2][uSesiCol]).trim();
          if (uSesiVal && uSesiVal !== '-' && uSesiVal.toLowerCase() !== 'semua' && distinctSessions.indexOf(uSesiVal) === -1) {
            distinctSessions.push(uSesiVal);
          }
        }
      }
    }
    distinctSessions.sort();
    
    // Ambil daftar siswa aktif & guru aktif dari sheet Users
    var distinctStudents = [];
    var distinctTeachers = [];
    if (usersSheet) {
      var uDataStd = usersSheet.getDataRange().getValues();
      var uMapStd = getHeaderMap(usersSheet);
      var uUnameCol = uMapStd['username'] !== undefined ? uMapStd['username'] : 1;
      var uNamaCol = uMapStd['nama_lengkap'] !== undefined ? uMapStd['nama_lengkap'] : 3;
      var uRoleCol = uMapStd['role'] !== undefined ? uMapStd['role'] : 4;
      var uStdKelasCol = uMapStd['kelas'] !== undefined ? uMapStd['kelas'] : 5;
      var uStdSesiCol = uMapStd['sesi'] !== undefined ? uMapStd['sesi'] : -1;
      var uStatusCol = uMapStd['status_aktif'] !== undefined ? uMapStd['status_aktif'] : 7;
      
      for (var us = 1; us < uDataStd.length; us++) {
        var rVal = toSafeString(uDataStd[us][uRoleCol]).toLowerCase();
        var uName = toSafeString(uDataStd[us][uUnameCol]).trim();
        var stVal = uStatusCol !== -1 ? toSafeString(uDataStd[us][uStatusCol]).toLowerCase() : 'aktif';
        if (uName && (rVal === 'siswa' || !rVal) && stVal !== 'nonaktif') {
          distinctStudents.push({
            username: uName,
            nama_lengkap: toSafeString(uDataStd[us][uNamaCol]).trim() || uName,
            kelas: toSafeString(uDataStd[us][uStdKelasCol]).trim() || '-',
            sesi: uStdSesiCol !== -1 ? (toSafeString(uDataStd[us][uStdSesiCol]).trim() || '-') : '-'
          });
        }
        if (uName && (rVal === 'guru' || rVal === 'admin') && stVal !== 'nonaktif') {
          distinctTeachers.push({
            username: uName,
            nama_lengkap: toSafeString(uDataStd[us][uNamaCol]).trim() || uName,
            role: toSafeString(uDataStd[us][uRoleCol]) || (rVal === 'admin' ? 'Admin' : 'Guru')
          });
        }
      }
      distinctStudents.sort(function(a, b) {
        if (a.kelas !== b.kelas) return a.kelas.localeCompare(b.kelas);
        return a.nama_lengkap.localeCompare(b.nama_lengkap);
      });
      distinctTeachers.sort(function(a, b) {
        var rA = (a.role || '').toLowerCase();
        var rB = (b.role || '').toLowerCase();
        if (rA !== rB) {
          if (rA === 'admin') return -1;
          if (rB === 'admin') return 1;
        }
        return a.nama_lengkap.localeCompare(b.nama_lengkap);
      });
    }

    return {
      success: true,
      exams: list,
      available_classes: distinctClasses,
      available_grades: distinctGrades,
      class_grade_map: classGradeMap,
      available_sessions: distinctSessions,
      available_students: distinctStudents,
      available_teachers: distinctTeachers
    };
  } catch (err) {
    return { success: false, message: 'Gagal memuat daftar ujian: ' + err.message };
  }
}

function saveExam(username, token, examData) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(15000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.EXAMS);
    if (!sheet) {
      CreateDb();
      sheet = getSheetSafe(ss, SHEETS.EXAMS);
    }
    
    // Pastikan kolom acak_opsi, token_ujian, kelas, sesi, tipe_peserta, peserta_khusus, kolaborator, tingkat, jumlah_soal_tampil, tampilkan_pembahasan, jumlah_percobaan, max_violations, dan tindakan_pelanggaran ada di sheet
    var map = getHeaderMap(sheet);
    if (map['acak_opsi'] === undefined || map['token_ujian'] === undefined || map['kelas'] === undefined || map['sesi'] === undefined || map['tipe_peserta'] === undefined || map['peserta_khusus'] === undefined || map['kolaborator'] === undefined || map['tingkat'] === undefined || map['jumlah_soal_tampil'] === undefined || map['tampilkan_pembahasan'] === undefined || map['jumlah_percobaan'] === undefined || map['max_violations'] === undefined || map['tindakan_pelanggaran'] === undefined) {
      CreateDb();
      map = getHeaderMap(sheet);
    }
    
    var data = sheet.getDataRange().getValues();
    var isEdit = Boolean(examData.id_ujian);
    var targetRow = -1;
    var idCol = map['id_ujian'] !== undefined ? map['id_ujian'] : 0;
    
    var namaVal = toSafeString(examData.nama_ujian);
    var durasiVal = Number(examData.durasi_menit) || 60;
    var mulaiVal = formatDateTimeIso(examData.waktu_mulai);
    var selesaiVal = formatDateTimeIso(examData.waktu_selesai);
    var acakSoalVal = toSafeString(examData.acak_soal) || 'Ya';
    var acakOpsiVal = toSafeString(examData.acak_opsi) || 'Ya';
    var tokenVal = toSafeString(examData.token_ujian).toUpperCase();
    var tipePesertaVal = toSafeString(examData.tipe_peserta).toLowerCase() === 'khusus' ? 'khusus' : 'general';
    var pesertaKhususVal = '';
    var kelasVal = Array.isArray(examData.kelas) ? examData.kelas.join(', ') : toSafeString(examData.kelas);
    var sesiVal = Array.isArray(examData.sesi) ? examData.sesi.join(', ') : toSafeString(examData.sesi).trim();
    var tingkatVal = toSafeString(examData.tingkat).trim();

    // Jumlah soal yang ditampilkan ke setiap siswa (0 / kosong = tampilkan seluruh soal)
    var jumlahTampilRaw = examData.jumlah_soal_tampil;
    var jumlahSoalTampilVal = 0;
    if (jumlahTampilRaw !== '' && jumlahTampilRaw !== null && jumlahTampilRaw !== undefined) {
      var parsedTampil = parseInt(jumlahTampilRaw, 10);
      if (isNaN(parsedTampil)) {
        return { success: false, message: 'Jumlah soal tampil harus berupa angka bulat positif (0 = tampilkan semua soal).' };
      }
      if (parsedTampil < 0) parsedTampil = 0;
      jumlahSoalTampilVal = parsedTampil;
    }

    // Izinkan siswa melihat kunci jawaban & pembahasan setelah ujian selesai ('Ya' / 'Tidak')
    var tampilkanPembahasanVal = toSafeString(examData.tampilkan_pembahasan).trim().toLowerCase() === 'ya' ? 'Ya' : 'Tidak';

    // Jumlah percobaan yang boleh dilakukan siswa (default 1, maksimum 99).
    // Jika lebih dari 1, nilai akhir yang dipakai adalah nilai tertinggi antar percobaan.
    var jumlahPercobaanVal = normalizeExamAttempts(examData.jumlah_percobaan);

    // Batas maksimal pelanggaran & tindakan saat batas tercapai kini UNIK PER UJIAN
    // (kolom Exams.max_violations & Exams.tindakan_pelanggaran), bukan pengaturan global.
    var maxViolationsVal = normalizeExamMaxViolations(examData.max_violations);
    var tindakanPelanggaranVal = normalizeViolationAction(examData.tindakan_pelanggaran);

    var kolaboratorVal = '';
    if (Array.isArray(examData.kolaborator)) {
      kolaboratorVal = examData.kolaborator.map(function(s) { return toSafeString(s).trim(); }).filter(Boolean).join(', ');
    } else {
      kolaboratorVal = toSafeString(examData.kolaborator).trim();
    }

    if (tipePesertaVal === 'khusus') {
      if (Array.isArray(examData.peserta_khusus)) {
        pesertaKhususVal = examData.peserta_khusus.map(function(s) { return toSafeString(s).trim(); }).filter(Boolean).join(', ');
      } else {
        pesertaKhususVal = toSafeString(examData.peserta_khusus).trim();
      }
      if (!pesertaKhususVal) {
        return { success: false, message: 'Untuk ujian target peserta khusus, minimal satu siswa harus dipilih.' };
      }
      if (!sesiVal) sesiVal = 'Semua Sesi';
      if (!kelasVal) kelasVal = 'Peserta Khusus';
    } else {
      if (!sesiVal) {
        return { success: false, message: 'Target sesi peserta wajib dipilih atau ditentukan.' };
      }
    }
    // Dapatkan role pemanggil untuk validasi penugasan pengampu ujian
    var callerRole = '';
    var usersSheet = getSheetSafe(ss, SHEETS.USERS);
    if (usersSheet) {
      var uData = usersSheet.getDataRange().getValues();
      var uMap = getHeaderMap(usersSheet);
      var uCol = uMap['username'] !== undefined ? uMap['username'] : 1;
      var rCol = uMap['role'] !== undefined ? uMap['role'] : 4;
      for (var u = 1; u < uData.length; u++) {
        if (toSafeString(uData[u][uCol]).toLowerCase() === username.toLowerCase()) {
          callerRole = toSafeString(uData[u][rCol]).toLowerCase();
          break;
        }
      }
    }

    // Jika Admin: bebas assign ke guru lain atau dirinya sendiri
    // Jika Guru: otomatis diassign ke akunnya sendiri (tidak bisa ubah pengampu)
    var guruVal = username;
    if (callerRole === 'admin' && examData.id_guru) {
      guruVal = toSafeString(examData.id_guru).trim();
    }
    
    if (isEdit) {
      for (var i = 1; i < data.length; i++) {
        if (toSafeString(data[i][idCol]) === examData.id_ujian) {
          targetRow = i + 1;
          break;
        }
      }
      
      if (targetRow === -1) {
        return { success: false, message: 'Data ujian tidak ditemukan.' };
      }

      var existingGuru = map['id_guru'] !== undefined ? toSafeString(data[targetRow - 1][map['id_guru']]).trim() : '';
      var existingKolab = map['kolaborator'] !== undefined ? toSafeString(data[targetRow - 1][map['kolaborator']]).trim() : '';
      var existingKolabList = existingKolab ? existingKolab.split(',').map(function(s) { return s.trim().toLowerCase(); }).filter(Boolean) : [];

      if (callerRole === 'guru') {
        var isOwner = (existingGuru.toLowerCase() === username.toLowerCase()) || (!existingGuru);
        var isKolab = existingKolabList.indexOf(username.toLowerCase()) !== -1;
        if (!isOwner && !isKolab) {
          return { success: false, message: 'Anda tidak memiliki hak akses untuk mengedit ujian ini.' };
        }
        // Jika yang mengedit adalah kolaborator (bukan pemilik langsung), pertahankan pemilik asli ujian!
        if (!isOwner && isKolab) {
          guruVal = existingGuru;
        }
      }
      
      if (map['nama_ujian'] !== undefined) sheet.getRange(targetRow, map['nama_ujian'] + 1).setValue(namaVal);
      if (map['durasi_menit'] !== undefined) sheet.getRange(targetRow, map['durasi_menit'] + 1).setValue(durasiVal);
      if (map['waktu_mulai'] !== undefined) sheet.getRange(targetRow, map['waktu_mulai'] + 1).setNumberFormat('@').setValue(mulaiVal);
      if (map['waktu_selesai'] !== undefined) sheet.getRange(targetRow, map['waktu_selesai'] + 1).setNumberFormat('@').setValue(selesaiVal);
      if (map['acak_soal'] !== undefined) sheet.getRange(targetRow, map['acak_soal'] + 1).setValue(acakSoalVal);
      if (map['acak_opsi'] !== undefined) sheet.getRange(targetRow, map['acak_opsi'] + 1).setValue(acakOpsiVal);
      if (map['token_ujian'] !== undefined) sheet.getRange(targetRow, map['token_ujian'] + 1).setValue(tokenVal);
      if (map['kelas'] !== undefined) sheet.getRange(targetRow, map['kelas'] + 1).setValue(kelasVal);
      if (map['sesi'] !== undefined) sheet.getRange(targetRow, map['sesi'] + 1).setValue(sesiVal);
      if (map['tipe_peserta'] !== undefined) sheet.getRange(targetRow, map['tipe_peserta'] + 1).setValue(tipePesertaVal);
      if (map['peserta_khusus'] !== undefined) sheet.getRange(targetRow, map['peserta_khusus'] + 1).setValue(pesertaKhususVal);
      if (map['id_guru'] !== undefined) sheet.getRange(targetRow, map['id_guru'] + 1).setValue(guruVal);
      if (map['kolaborator'] !== undefined) sheet.getRange(targetRow, map['kolaborator'] + 1).setValue(kolaboratorVal);
      if (map['tingkat'] !== undefined) sheet.getRange(targetRow, map['tingkat'] + 1).setValue(tingkatVal);
      if (map['jumlah_soal_tampil'] !== undefined) sheet.getRange(targetRow, map['jumlah_soal_tampil'] + 1).setValue(jumlahSoalTampilVal);
      if (map['tampilkan_pembahasan'] !== undefined) sheet.getRange(targetRow, map['tampilkan_pembahasan'] + 1).setValue(tampilkanPembahasanVal);
      if (map['jumlah_percobaan'] !== undefined) sheet.getRange(targetRow, map['jumlah_percobaan'] + 1).setNumberFormat('@').setValue(String(jumlahPercobaanVal));
      if (map['max_violations'] !== undefined) sheet.getRange(targetRow, map['max_violations'] + 1).setNumberFormat('@').setValue(String(maxViolationsVal));
      if (map['tindakan_pelanggaran'] !== undefined) sheet.getRange(targetRow, map['tindakan_pelanggaran'] + 1).setValue(tindakanPelanggaranVal);
      
      SpreadsheetApp.flush();
      var pengampuLog = ' (Pengampu: ' + guruVal + ')';
      var kolabLog = kolaboratorVal ? ' (Kolaborator: ' + kolaboratorVal + ')' : '';
      var targetLog = tipePesertaVal === 'khusus' 
        ? ' (Target: Peserta Khusus [' + pesertaKhususVal + '])' 
        : (kelasVal ? ' (Kelas: ' + kelasVal + ')' : '') + (sesiVal ? ' (Sesi: ' + sesiVal + ')' : '');
      var tampilLog = jumlahSoalTampilVal > 0 ? ' (Jumlah Soal Tampil: ' + jumlahSoalTampilVal + ' acak/soal)' : '';
      var percobaanLog = ' (Jumlah Percobaan: ' + jumlahPercobaanVal + ')';
      var pelanggaranLog = ' (Maks Pelanggaran: ' + maxViolationsVal + 'x / ' + tindakanPelanggaranVal + ')';
      logActivity(username, 'Update Exam', 'Mengupdate ujian: ' + namaVal + (tokenVal ? ' (Token: ' + tokenVal + ')' : '') + pengampuLog + kolabLog + targetLog + tampilLog + percobaanLog + pelanggaranLog);
      return { success: true, message: 'Ujian berhasil diperbarui.' };
    } else {
      var newExamId = 'EXM-' + Utilities.getUuid().substring(0, 8).toUpperCase();
      var numCols = Math.max(sheet.getLastColumn(), SHEET_HEADERS.Exams.length);
      var newRow = new Array(numCols).fill('');
      
      newRow[map['id_ujian'] !== undefined ? map['id_ujian'] : 0] = newExamId;
      newRow[map['nama_ujian'] !== undefined ? map['nama_ujian'] : 1] = namaVal;
      newRow[map['durasi_menit'] !== undefined ? map['durasi_menit'] : 2] = durasiVal;
      newRow[map['waktu_mulai'] !== undefined ? map['waktu_mulai'] : 3] = mulaiVal;
      newRow[map['waktu_selesai'] !== undefined ? map['waktu_selesai'] : 4] = selesaiVal;
      newRow[map['acak_soal'] !== undefined ? map['acak_soal'] : 5] = acakSoalVal;
      newRow[map['acak_opsi'] !== undefined ? map['acak_opsi'] : 6] = acakOpsiVal;
      newRow[map['token_ujian'] !== undefined ? map['token_ujian'] : 7] = tokenVal;
      if (map['kelas'] !== undefined) newRow[map['kelas']] = kelasVal;
      if (map['sesi'] !== undefined) newRow[map['sesi']] = sesiVal;
      if (map['tipe_peserta'] !== undefined) newRow[map['tipe_peserta']] = tipePesertaVal;
      if (map['peserta_khusus'] !== undefined) newRow[map['peserta_khusus']] = pesertaKhususVal;
      newRow[map['id_guru'] !== undefined ? map['id_guru'] : 9] = guruVal;
      if (map['kolaborator'] !== undefined) newRow[map['kolaborator']] = kolaboratorVal;
      if (map['tingkat'] !== undefined) newRow[map['tingkat']] = tingkatVal;
      if (map['jumlah_soal_tampil'] !== undefined) newRow[map['jumlah_soal_tampil']] = jumlahSoalTampilVal;
      if (map['tampilkan_pembahasan'] !== undefined) newRow[map['tampilkan_pembahasan']] = tampilkanPembahasanVal;
      if (map['jumlah_percobaan'] !== undefined) newRow[map['jumlah_percobaan']] = String(jumlahPercobaanVal);
      if (map['max_violations'] !== undefined) newRow[map['max_violations']] = String(maxViolationsVal);
      if (map['tindakan_pelanggaran'] !== undefined) newRow[map['tindakan_pelanggaran']] = tindakanPelanggaranVal;
      
      sheet.appendRow(newRow);
      var newLastRow = sheet.getLastRow();
      if (map['waktu_mulai'] !== undefined) sheet.getRange(newLastRow, map['waktu_mulai'] + 1).setNumberFormat('@').setValue(mulaiVal);
      if (map['waktu_selesai'] !== undefined) sheet.getRange(newLastRow, map['waktu_selesai'] + 1).setNumberFormat('@').setValue(selesaiVal);
      SpreadsheetApp.flush();
      
      var targetLog2 = tipePesertaVal === 'khusus' 
        ? ' (Target: Peserta Khusus [' + pesertaKhususVal + '])' 
        : (kelasVal ? ' (Kelas: ' + kelasVal + ')' : '') + (sesiVal ? ' (Sesi: ' + sesiVal + ')' : '');
      var tampilLog2 = jumlahSoalTampilVal > 0 ? ' (Jumlah Soal Tampil: ' + jumlahSoalTampilVal + ' acak/soal)' : '';
      var percobaanLog2 = ' (Jumlah Percobaan: ' + jumlahPercobaanVal + ')';
      var pelanggaranLog2 = ' (Maks Pelanggaran: ' + maxViolationsVal + 'x / ' + tindakanPelanggaranVal + ')';
      logActivity(username, 'Create Exam', 'Membuat ujian baru: ' + namaVal + (tokenVal ? ' (Token: ' + tokenVal + ')' : '') + pengampuLog + (kolaboratorVal ? ' (Kolaborator: ' + kolaboratorVal + ')' : '') + targetLog2 + tampilLog2 + percobaanLog2 + pelanggaranLog2);
      return { success: true, message: 'Ujian berhasil dibuat.', id_ujian: newExamId };
    }
  } catch (e) {
    return { success: false, message: 'Gagal menyimpan ujian: ' + e.message };
  } finally {
    lock.releaseLock();
  }
}

function deleteExam(username, token, id_ujian) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(15000);
  try {
    var ss = getSpreadsheet();
    var examsSheet = ss.getSheetByName(SHEETS.EXAMS);
    var questionsSheet = ss.getSheetByName(SHEETS.QUESTIONS);
    var resultsSheet = ss.getSheetByName(SHEETS.RESULTS);
    
    // 1. Hapus dari sheet Exams
    if (examsSheet) {
      var examsData = examsSheet.getDataRange().getValues();
      var eMap = getHeaderMap(examsSheet);
      var eIdCol = eMap['id_ujian'] !== undefined ? eMap['id_ujian'] : 0;
      for (var i = examsData.length - 1; i >= 1; i--) {
        if (toSafeString(examsData[i][eIdCol]) === id_ujian) {
          examsSheet.deleteRow(i + 1);
        }
      }
    }
    
    // 2. Hapus butir soal terkait di sheet Questions
    if (questionsSheet) {
      var qMap = getHeaderMap(questionsSheet);
      var qExamIdCol = qMap['id_ujian'] !== undefined ? qMap['id_ujian'] : 1;
      var qData = questionsSheet.getDataRange().getValues();
      for (var j = qData.length - 1; j >= 1; j--) {
        if (toSafeString(qData[j][qExamIdCol]) === id_ujian) {
          questionsSheet.deleteRow(j + 1);
        }
      }
    }
    
    // 3. Hapus seluruh data pengerjaan & hasil siswa untuk ujian ini di sheet Results
    var deletedResultsCount = 0;
    if (resultsSheet) {
      var rMap = getHeaderMap(resultsSheet);
      var rExamIdCol = rMap['id_ujian'] !== undefined ? rMap['id_ujian'] : 1;
      var rData = resultsSheet.getDataRange().getValues();
      for (var k = rData.length - 1; k >= 1; k--) {
        if (toSafeString(rData[k][rExamIdCol]) === id_ujian) {
          resultsSheet.deleteRow(k + 1);
          deletedResultsCount++;
        }
      }
    }
    
    SpreadsheetApp.flush();
    logActivity(username, 'Delete Exam', 'Menghapus ujian ID: ' + id_ujian + ' beserta butir soal dan ' + deletedResultsCount + ' data pengerjaan siswa terkait.');
    return { 
      success: true, 
      message: 'Ujian beserta bank soal dan seluruh data pengerjaan siswa berhasil dihapus.' 
    };
  } catch (e) {
    return { success: false, message: e.message };
  } finally {
    lock.releaseLock();
  }
}

// BANK SOAL
function getExamQuestions(username, token, id_ujian) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid.' };
  }
  
  var ss = getSpreadsheet();
  var sheet = getSheetSafe(ss, SHEETS.QUESTIONS);
  if (!sheet) {
    return { success: true, questions: [] };
  }
  
  var data = sheet.getDataRange().getDisplayValues();
  var qMap = getHeaderMap(sheet);
  var letters = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];
  
  var idUjianCol = qMap['id_ujian'] !== undefined ? qMap['id_ujian'] : 1;
  var idSoalCol = qMap['id_soal'] !== undefined ? qMap['id_soal'] : 0;
  var teksCol = qMap['teks_soal'] !== undefined ? qMap['teks_soal'] : 2;
  var urlCol = qMap['url_gambar'] !== undefined ? qMap['url_gambar'] : -1;
  var kunciCol = qMap['kunci_jawaban'] !== undefined ? qMap['kunci_jawaban'] : 8;
  var bobotCol = qMap['bobot'] !== undefined ? qMap['bobot'] : 9;
  var idTeksCol = qMap['id_teks'] !== undefined ? qMap['id_teks'] : -1;
  var pembahasanCol = qMap['pembahasan'] !== undefined ? qMap['pembahasan'] : -1;
  
  // Teks/Konteks/Stimulan milik ujian ini (untuk ditampilkan di atas pertanyaan)
  var textMap = getExamTextMap(ss, id_ujian);
  
  var questions = [];
  for (var i = 1; i < data.length; i++) {
    if (toSafeString(data[i][idUjianCol]) === id_ujian) {
      var qIdTeks = idTeksCol !== -1 ? toSafeString(data[i][idTeksCol]).trim() : '';
      var qTeksObj = textMap[qIdTeks] || null;
      var qItem = {
        id_soal: toSafeString(data[i][idSoalCol]),
        id_ujian: toSafeString(data[i][idUjianCol]),
        teks_soal: toSafeString(data[i][teksCol]),
        url_gambar: urlCol !== -1 ? toSafeString(data[i][urlCol]) : '',
        kunci_jawaban: toSafeString(data[i][kunciCol]).toUpperCase(),
        bobot: Number(data[i][bobotCol]) || 1,
        id_teks: qIdTeks,
        pembahasan: pembahasanCol !== -1 ? toSafeString(data[i][pembahasanCol]) : '',
        nama_teks: qTeksObj ? qTeksObj.nama_teks : '',
        teks_konteks: qTeksObj ? qTeksObj.isi_teks : ''
      };
      
      // Load all available options A..J
      letters.forEach(function(l) {
        var key = 'opsi_' + l;
        qItem[key] = (qMap[key] !== undefined) ? toSafeString(data[i][qMap[key]]) : '';
      });
      
      questions.push(qItem);
    }
  }
  
  return { success: true, questions: questions };
}

function saveQuestion(username, token, qData) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(10000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.QUESTIONS);
    if (!sheet) {
      return { success: false, message: 'Sheet Questions tidak ditemukan.' };
    }
    
    // Pastikan seluruh kolom target ada di sheet
    var qMap = getHeaderMap(sheet);
    var targetHeaders = SHEET_HEADERS.Questions;
    var headerAdded = false;
    targetHeaders.forEach(function(th) {
      if (qMap[th.toLowerCase()] === undefined) {
        var newCol = sheet.getLastColumn() + 1;
        sheet.getRange(1, newCol).setValue(th).setFontWeight('bold').setBackground('#1e293b').setFontColor('#ffffff');
        headerAdded = true;
      }
    });
    if (headerAdded) {
      SpreadsheetApp.flush();
      qMap = getHeaderMap(sheet);
    }
    
    var data = sheet.getDataRange().getDisplayValues();
    var isEdit = Boolean(qData.id_soal);
    var targetRow = -1;
    var letters = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];
    var idSoalCol = qMap['id_soal'] !== undefined ? qMap['id_soal'] : 0;
    
    if (isEdit) {
      for (var i = 1; i < data.length; i++) {
        if (toSafeString(data[i][idSoalCol]) === qData.id_soal) {
          targetRow = i + 1;
          break;
        }
      }
      
      if (targetRow === -1) {
        return { success: false, message: 'Soal tidak ditemukan.' };
      }
      
      if (qMap['teks_soal'] !== undefined) sheet.getRange(targetRow, qMap['teks_soal'] + 1).setNumberFormat('@').setValue(toSafeString(qData.teks_soal));
      if (qMap['url_gambar'] !== undefined) sheet.getRange(targetRow, qMap['url_gambar'] + 1).setNumberFormat('@').setValue(toSafeString(qData.url_gambar));
      if (qMap['id_teks'] !== undefined) sheet.getRange(targetRow, qMap['id_teks'] + 1).setNumberFormat('@').setValue(toSafeString(qData.id_teks));
      
      letters.forEach(function(l) {
        var key = 'opsi_' + l;
        if (qMap[key] !== undefined) {
          sheet.getRange(targetRow, qMap[key] + 1).setNumberFormat('@').setValue(toSafeString(qData[key]));
        }
      });
      
      if (qMap['kunci_jawaban'] !== undefined) sheet.getRange(targetRow, qMap['kunci_jawaban'] + 1).setValue(toSafeString(qData.kunci_jawaban || 'A').toUpperCase());
      if (qMap['bobot'] !== undefined) sheet.getRange(targetRow, qMap['bobot'] + 1).setValue(Number(qData.bobot) || 1);
      if (qMap['pembahasan'] !== undefined) sheet.getRange(targetRow, qMap['pembahasan'] + 1).setNumberFormat('@').setValue(toSafeString(qData.pembahasan));
      
      return { success: true, message: 'Soal berhasil diperbarui.' };
    } else {
      var newQId = 'QST-' + Utilities.getUuid().substring(0, 8).toUpperCase();
      var lastCol = sheet.getLastColumn();
      var newRow = new Array(lastCol);
      for (var c = 0; c < lastCol; c++) newRow[c] = '';
      
      if (qMap['id_soal'] !== undefined) newRow[qMap['id_soal']] = newQId;
      if (qMap['id_ujian'] !== undefined) newRow[qMap['id_ujian']] = toSafeString(qData.id_ujian);
      if (qMap['teks_soal'] !== undefined) newRow[qMap['teks_soal']] = toSafeString(qData.teks_soal);
      if (qMap['url_gambar'] !== undefined) newRow[qMap['url_gambar']] = toSafeString(qData.url_gambar);
      if (qMap['id_teks'] !== undefined) newRow[qMap['id_teks']] = toSafeString(qData.id_teks);
      
      letters.forEach(function(l) {
        var key = 'opsi_' + l;
        if (qMap[key] !== undefined) {
          newRow[qMap[key]] = toSafeString(qData[key]);
        }
      });
      
      if (qMap['kunci_jawaban'] !== undefined) newRow[qMap['kunci_jawaban']] = toSafeString(qData.kunci_jawaban || 'A').toUpperCase();
      if (qMap['bobot'] !== undefined) newRow[qMap['bobot']] = Number(qData.bobot) || 1;
      if (qMap['pembahasan'] !== undefined) newRow[qMap['pembahasan']] = toSafeString(qData.pembahasan);
      
      sheet.appendRow(newRow);
      var newRowNum = sheet.getLastRow();
      sheet.getRange(newRowNum, 1, 1, lastCol).setNumberFormat('@');
      return { success: true, message: 'Soal baru berhasil ditambahkan.', id_soal: newQId };
    }
  } catch (e) {
    return { success: false, message: e.message };
  } finally {
    lock.releaseLock();
  }
}

function deleteQuestion(username, token, id_soal) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(10000);
  try {
    var ss = getSpreadsheet();
    var sheet = ss.getSheetByName(SHEETS.QUESTIONS);
    var data = sheet.getDataRange().getValues();
    
    for (var i = 1; i < data.length; i++) {
      if (data[i][0] === id_soal) {
        sheet.deleteRow(i + 1);
        return { success: true, message: 'Soal berhasil dihapus.' };
      }
    }
    return { success: false, message: 'Soal tidak ditemukan.' };
  } catch (e) {
    return { success: false, message: e.message };
  } finally {
    lock.releaseLock();
  }
}

/**
 * ==========================================
 * 8.C. MODUL TEKS / KONTEKS / STIMULAN UJIAN
 * ==========================================
 * Area teks/konteks/stimulan (seperti passage pada soal Reading TOEFL & TKA)
 * yang ditampilkan DI ATAS pertanyaan pada halaman ujian siswa.
 * Data disimpan pada sheet `TeksUjian`, setiap butir soal menautkannya
 * melalui kolom `Questions.id_teks`.
 */

/**
 * Helper: Memuat peta teks/konteks/stimulan milik sebuah ujian.
 * @returns {Object} { [id_teks]: { nama_teks: string, isi_teks: string } }
 */
function getExamTextMap(ss, id_ujian) {
  var map = {};
  if (!ss || !id_ujian) return map;
  var sheet = getSheetSafe(ss, SHEETS.TEKS_UJIAN);
  if (!sheet) return map;

  var data = sheet.getDataRange().getDisplayValues();
  var tMap = getHeaderMap(sheet);
  var idTxtCol = tMap['id_teks'] !== undefined ? tMap['id_teks'] : 0;
  var idUjianCol = tMap['id_ujian'] !== undefined ? tMap['id_ujian'] : 1;
  var namaCol = tMap['nama_teks'] !== undefined ? tMap['nama_teks'] : 2;
  var isiCol = tMap['isi_teks'] !== undefined ? tMap['isi_teks'] : 3;
  var target = toSafeString(id_ujian).trim();

  for (var i = 1; i < data.length; i++) {
    if (toSafeString(data[i][idUjianCol]).trim() !== target) continue;
    var idTxt = toSafeString(data[i][idTxtCol]).trim();
    if (!idTxt) continue;
    map[idTxt] = {
      nama_teks: toSafeString(data[i][namaCol]),
      isi_teks: toSafeString(data[i][isiCol])
    };
  }
  return map;
}

/**
 * Helper: Memastikan sheet TeksUjian beserta seluruh kolomnya tersedia (migrasi ringan).
 * Memanggil CreateDb() bila sheet belum ada (CreateDb tidak memakai lock sehingga aman di dalam locked function).
 */
function ensureExamTextSheet(ss) {
  var sheet = getSheetSafe(ss, SHEETS.TEKS_UJIAN);
  if (!sheet) {
    CreateDb();
    sheet = getSheetSafe(ss, SHEETS.TEKS_UJIAN);
  }
  if (!sheet) return null;

  var tMap = getHeaderMap(sheet);
  var headerAdded = false;
  SHEET_HEADERS.TeksUjian.forEach(function(th) {
    if (tMap[th.toLowerCase()] === undefined) {
      var newCol = sheet.getLastColumn() + 1;
      sheet.getRange(1, newCol).setValue(th).setFontWeight('bold').setBackground('#1e293b').setFontColor('#ffffff');
      headerAdded = true;
    }
  });
  if (headerAdded) {
    SpreadsheetApp.flush();
    tMap = getHeaderMap(sheet);
  }
  return sheet;
}

/**
 * Mengambil daftar teks/konteks/stimulan yang tersimpan pada sebuah ujian.
 */
function getExamTexts(username, token, id_ujian) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid.' };
  }
  if (!id_ujian) {
    return { success: false, message: 'ID Ujian tidak valid.' };
  }

  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.TEKS_UJIAN);
    if (!sheet) return { success: true, texts: [] };

    var data = sheet.getDataRange().getDisplayValues();
    var tMap = getHeaderMap(sheet);
    var idTxtCol = tMap['id_teks'] !== undefined ? tMap['id_teks'] : 0;
    var idUjianCol = tMap['id_ujian'] !== undefined ? tMap['id_ujian'] : 1;
    var namaCol = tMap['nama_teks'] !== undefined ? tMap['nama_teks'] : 2;
    var isiCol = tMap['isi_teks'] !== undefined ? tMap['isi_teks'] : 3;
    var target = toSafeString(id_ujian).trim();

    var texts = [];
    for (var i = 1; i < data.length; i++) {
      if (toSafeString(data[i][idUjianCol]).trim() !== target) continue;
      var idTxt = toSafeString(data[i][idTxtCol]).trim();
      if (!idTxt) continue;
      texts.push({
        id_teks: idTxt,
        id_ujian: target,
        nama_teks: toSafeString(data[i][namaCol]),
        isi_teks: toSafeString(data[i][isiCol])
      });
    }

    return { success: true, texts: texts };
  } catch (e) {
    return { success: false, message: 'Gagal memuat teks/konteks: ' + e.message };
  }
}

/**
 * Menambah atau mengubah teks/konteks/stimulan pada sebuah ujian.
 * qData: { id_teks (kosong = baru), id_ujian, nama_teks, isi_teks }
 */
function saveExamText(username, token, textData) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid.' };
  }
  if (!textData || !textData.id_ujian) {
    return { success: false, message: 'ID Ujian tidak valid.' };
  }

  var nama = toSafeString(textData.nama_teks).trim();
  var isi = toSafeString(textData.isi_teks).trim();
  if (!nama) {
    return { success: false, message: 'Nama/Judul teks wajib diisi.' };
  }
  if (!isi) {
    return { success: false, message: 'Teks/Konteks/Stimulan wajib diisi.' };
  }

  var lock = LockService.getScriptLock();
  lock.tryLock(10000);
  try {
    var ss = getSpreadsheet();
    var sheet = ensureExamTextSheet(ss);
    if (!sheet) {
      return { success: false, message: 'Sheet TeksUjian tidak ditemukan. Jalankan CreateDb() terlebih dahulu.' };
    }

    var tMap = getHeaderMap(sheet);
    var idTxtCol = tMap['id_teks'] !== undefined ? tMap['id_teks'] : 0;
    var idUjianCol = tMap['id_ujian'] !== undefined ? tMap['id_ujian'] : 1;
    var namaCol = tMap['nama_teks'] !== undefined ? tMap['nama_teks'] : 2;
    var isiCol = tMap['isi_teks'] !== undefined ? tMap['isi_teks'] : 3;

    var isEdit = Boolean(toSafeString(textData.id_teks).trim());

    if (isEdit) {
      var targetId = toSafeString(textData.id_teks).trim();
      var data = sheet.getDataRange().getValues();
      var targetRow = -1;
      for (var i = 1; i < data.length; i++) {
        if (toSafeString(data[i][idTxtCol]).trim() === targetId) {
          targetRow = i + 1;
          break;
        }
      }
      if (targetRow === -1) {
        return { success: false, message: 'Teks/Konteks tidak ditemukan.' };
      }

      sheet.getRange(targetRow, namaCol + 1).setNumberFormat('@').setValue(nama);
      sheet.getRange(targetRow, isiCol + 1).setNumberFormat('@').setValue(isi);
      if (tMap['id_ujian'] !== undefined) {
        sheet.getRange(targetRow, idUjianCol + 1).setNumberFormat('@').setValue(toSafeString(textData.id_ujian).trim());
      }
      SpreadsheetApp.flush();

      logActivity(username, 'Edit Teks Ujian', 'Memperbarui teks/konteks "' + nama + '" pada ujian ' + textData.id_ujian);
      return { success: true, id_teks: targetId, message: 'Teks/Konteks berhasil diperbarui.' };
    }

    var newId = 'TXT-' + Utilities.getUuid().substring(0, 8).toUpperCase();
    var lastCol = sheet.getLastColumn();
    var newRow = new Array(lastCol);
    for (var c = 0; c < lastCol; c++) newRow[c] = '';

    newRow[idTxtCol] = newId;
    newRow[idUjianCol] = toSafeString(textData.id_ujian).trim();
    newRow[namaCol] = nama;
    newRow[isiCol] = isi;

    sheet.appendRow(newRow);
    var newRowNum = sheet.getLastRow();
    sheet.getRange(newRowNum, 1, 1, lastCol).setNumberFormat('@');
    SpreadsheetApp.flush();

    logActivity(username, 'Tambah Teks Ujian', 'Menambah teks/konteks "' + nama + '" pada ujian ' + textData.id_ujian);
    return { success: true, id_teks: newId, message: 'Teks/Konteks berhasil ditambahkan.' };
  } catch (e) {
    return { success: false, message: 'Gagal menyimpan teks/konteks: ' + e.message };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Menghapus teks/konteks/stimulan dan melepaskannya dari seluruh butir soal yang memakainya.
 */
function deleteExamText(username, token, id_teks) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid.' };
  }
  if (!id_teks) {
    return { success: false, message: 'ID teks tidak valid.' };
  }

  var lock = LockService.getScriptLock();
  lock.tryLock(10000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.TEKS_UJIAN);
    if (!sheet) {
      return { success: false, message: 'Teks/Konteks tidak ditemukan.' };
    }

    var tMap = getHeaderMap(sheet);
    var idTxtCol = tMap['id_teks'] !== undefined ? tMap['id_teks'] : 0;
    var namaCol = tMap['nama_teks'] !== undefined ? tMap['nama_teks'] : 2;
    var targetId = toSafeString(id_teks).trim();

    var data = sheet.getDataRange().getValues();
    var targetRow = -1;
    var namaTeks = '';
    for (var i = 1; i < data.length; i++) {
      if (toSafeString(data[i][idTxtCol]).trim() === targetId) {
        targetRow = i + 1;
        namaTeks = toSafeString(data[i][namaCol]);
        break;
      }
    }
    if (targetRow === -1) {
      return { success: false, message: 'Teks/Konteks tidak ditemukan.' };
    }

    sheet.deleteRow(targetRow);

    // Lepaskan keterkaitan pada butir soal yang memakai teks ini (soal tetap ada, tanpa teks)
    var released = 0;
    var qSheet = getSheetSafe(ss, SHEETS.QUESTIONS);
    if (qSheet) {
      var qMap = getHeaderMap(qSheet);
      var qIdTeksCol = qMap['id_teks'];
      if (qIdTeksCol !== undefined) {
        var qData = qSheet.getDataRange().getValues();
        for (var q = 1; q < qData.length; q++) {
          if (toSafeString(qData[q][qIdTeksCol]).trim() === targetId) {
            qSheet.getRange(q + 1, qIdTeksCol + 1).setNumberFormat('@').setValue('');
            released++;
          }
        }
      }
    }

    SpreadsheetApp.flush();

    logActivity(username, 'Hapus Teks Ujian', 'Menghapus teks/konteks "' + namaTeks + '" (' + released + ' butir soal dilepas dari teks)');
    return {
      success: true,
      message: 'Teks/Konteks berhasil dihapus.' + (released > 0 ? ' ' + released + ' butir soal kini tanpa teks.' : '')
    };
  } catch (e) {
    return { success: false, message: 'Gagal menghapus teks/konteks: ' + e.message };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Mengimpor banyak butir soal sekaligus dari Excel/CSV ke bank soal ujian tertentu
 */
function importQuestions(username, token, id_ujian, questionsList) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }
  
  if (!id_ujian) {
    return { success: false, message: 'ID Ujian tidak valid.' };
  }
  
  if (!questionsList || !Array.isArray(questionsList) || questionsList.length === 0) {
    return { success: false, message: 'Data soal yang akan diimpor kosong.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(30000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.QUESTIONS);
    if (!sheet) {
      return { success: false, message: 'Sheet Questions tidak ditemukan. Jalankan CreateDb() terlebih dahulu.' };
    }
    
    // Pastikan seluruh kolom target ada di sheet
    var qMap = getHeaderMap(sheet);
    var targetHeaders = SHEET_HEADERS.Questions;
    var headerAdded = false;
    targetHeaders.forEach(function(th) {
      if (qMap[th.toLowerCase()] === undefined) {
        var newCol = sheet.getLastColumn() + 1;
        sheet.getRange(1, newCol).setValue(th).setFontWeight('bold').setBackground('#1e293b').setFontColor('#ffffff');
        headerAdded = true;
      }
    });
    if (headerAdded) {
      SpreadsheetApp.flush();
      qMap = getHeaderMap(sheet);
    }
    
    var letters = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];
    var lastCol = sheet.getLastColumn();
    var rowsToAdd = [];
    
    for (var i = 0; i < questionsList.length; i++) {
      var q = questionsList[i];
      var teks = toSafeString(q.teks_soal || q.soal || '').trim();
      if (!teks) continue; // Lewati baris tanpa pertanyaan
      
      var newQId = 'QST-' + Utilities.getUuid().substring(0, 8).toUpperCase();
      var newRow = new Array(lastCol);
      for (var c = 0; c < lastCol; c++) newRow[c] = '';
      
      if (qMap['id_soal'] !== undefined) newRow[qMap['id_soal']] = newQId;
      if (qMap['id_ujian'] !== undefined) newRow[qMap['id_ujian']] = toSafeString(id_ujian);
      if (qMap['teks_soal'] !== undefined) newRow[qMap['teks_soal']] = teks;
      if (qMap['url_gambar'] !== undefined) newRow[qMap['url_gambar']] = toSafeString(q.url_gambar || '');
      if (qMap['id_teks'] !== undefined) newRow[qMap['id_teks']] = toSafeString(q.id_teks || '');
      
      letters.forEach(function(l) {
        var key = 'opsi_' + l;
        if (qMap[key] !== undefined) {
          newRow[qMap[key]] = toSafeString(q[key] || '');
        }
      });
      
      var keyAns = toSafeString(q.kunci_jawaban || 'A').trim().toUpperCase();
      if (qMap['kunci_jawaban'] !== undefined) newRow[qMap['kunci_jawaban']] = keyAns || 'A';
      if (qMap['bobot'] !== undefined) newRow[qMap['bobot']] = Number(q.bobot) || 1;
      if (qMap['pembahasan'] !== undefined) newRow[qMap['pembahasan']] = toSafeString(q.pembahasan || '');
      
      rowsToAdd.push(newRow);
    }
    
    if (rowsToAdd.length === 0) {
      return { success: false, message: 'Tidak ada baris soal yang valid untuk disimpan.' };
    }
    
    var startRow = sheet.getLastRow() + 1;
    var newRange = sheet.getRange(startRow, 1, rowsToAdd.length, lastCol);
    newRange.setNumberFormat('@'); // Plain text format agar opsi seperti tanggal tidak berubah
    newRange.setValues(rowsToAdd);
    SpreadsheetApp.flush();
    
    logActivity(username, 'Import Questions', 'Berhasil mengimpor ' + rowsToAdd.length + ' butir soal ke ujian ' + id_ujian);
    return { success: true, count: rowsToAdd.length, message: 'Berhasil mengimpor ' + rowsToAdd.length + ' butir soal dari Excel.' };
  } catch (err) {
    return { success: false, message: 'Gagal mengimpor soal: ' + err.message };
  } finally {
    lock.releaseLock();
  }
}

// ==========================================
// 8.B. MODUL INTEGRASI GEMINI AI (PEMBUATAN SOAL)
// ==========================================

/**
 * Mengambil daftar model Gemini terbaru dari Google Generative Language API
 * Hanya memfilter model yang mendukung generateContent (khusus teks/konten)
 */
function getGeminiModels(apiKey) {
  try {
    var key = toSafeString(apiKey).trim();
    if (!key) {
      var s = getSettings();
      key = (s.settings && s.settings.gemini_api_key) ? s.settings.gemini_api_key.trim() : '';
    }
    if (!key) {
      return { success: false, message: 'API Key belum diisi. Masukkan API Key terlebih dahulu.' };
    }
    
    var url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' + encodeURIComponent(key);
    var response = UrlFetchApp.fetch(url, {
      method: 'get',
      muteHttpExceptions: true
    });
    
    var code = response.getResponseCode();
    var content = response.getContentText();
    if (code !== 200) {
      var errObj = {};
      try { errObj = JSON.parse(content); } catch (e) {}
      var errMsg = (errObj.error && errObj.error.message) ? errObj.error.message : content;
      return { success: false, message: 'Gagal mengambil model (' + code + '): ' + errMsg };
    }
    
    var data = JSON.parse(content);
    var rawModels = data.models || [];
    
    // Filter hanya model yang mendukung generateContent dan khusus gemini text/content generation
    var validModels = [];
    rawModels.forEach(function(m) {
      var methods = m.supportedGenerationMethods || [];
      var name = (m.name || '').toLowerCase();
      var isGenerateContent = methods.indexOf('generateContent') !== -1;
      var isGemini = name.indexOf('gemini') !== -1;
      var isExcluded = name.indexOf('embedding') !== -1 || 
                       name.indexOf('imagen') !== -1 || 
                       name.indexOf('aqa') !== -1 || 
                       name.indexOf('robotics') !== -1;
      
      if (isGenerateContent && isGemini && !isExcluded) {
        var cleanId = m.name.replace(/^models\//, '');
        validModels.push({
          id: cleanId,
          displayName: m.displayName || cleanId,
          description: m.description || ''
        });
      }
    });
    
    if (validModels.length === 0) {
      validModels = [
        { id: 'gemini-2.0-flash', displayName: 'Gemini 2.0 Flash' },
        { id: 'gemini-2.0-flash-lite', displayName: 'Gemini 2.0 Flash Lite' },
        { id: 'gemini-1.5-flash', displayName: 'Gemini 1.5 Flash' },
        { id: 'gemini-1.5-pro', displayName: 'Gemini 1.5 Pro' }
      ];
    }
    
    return { success: true, models: validModels };
  } catch (err) {
    return { success: false, message: 'Kesalahan saat memuat model: ' + err.message };
  }
}

/**
 * Melakukan uji koneksi ke API Gemini dengan request minimal
 */
function testGeminiConnection(apiKey, modelId) {
  try {
    var key = toSafeString(apiKey).trim();
    if (!key) {
      var s = getSettings();
      key = (s.settings && s.settings.gemini_api_key) ? s.settings.gemini_api_key.trim() : '';
    }
    if (!key) {
      return { success: false, message: 'API Key belum diisi. Masukkan API Key terlebih dahulu.' };
    }
    
    var model = toSafeString(modelId).trim().replace(/^models\//, '');
    if (!model) {
      var s2 = getSettings();
      model = (s2.settings && s2.settings.gemini_model) ? s2.settings.gemini_model.trim() : 'gemini-2.0-flash';
    }
    
    var url = 'https://generativelanguage.googleapis.com/v1beta/models/' + encodeURIComponent(model) + ':generateContent?key=' + encodeURIComponent(key);
    var payload = {
      contents: [
        {
          parts: [{ text: 'Halo! Jawab dengan satu kata: Terhubung.' }]
        }
      ],
      generationConfig: {
        maxOutputTokens: 10,
        temperature: 0.1
      }
    };
    
    var startTime = new Date().getTime();
    var response = UrlFetchApp.fetch(url, {
      method: 'post',
      contentType: 'application/json',
      payload: JSON.stringify(payload),
      muteHttpExceptions: true
    });
    var latency = new Date().getTime() - startTime;
    
    var code = response.getResponseCode();
    var content = response.getContentText();
    
    if (code === 200) {
      return {
        success: true,
        latency: latency,
        model: model,
        message: 'Koneksi ke Gemini AI berhasil! Waktu respons: ' + latency + ' ms'
      };
    } else {
      var errObj = {};
      try { errObj = JSON.parse(content); } catch (e) {}
      var errMsg = (errObj.error && errObj.error.message) ? errObj.error.message : content;
      return {
        success: false,
        latency: latency,
        message: 'Koneksi gagal (HTTP ' + code + '): ' + errMsg
      };
    }
  } catch (err) {
    return { success: false, message: 'Terjadi kesalahan sistem saat uji koneksi: ' + err.message };
  }
}

/**
 * Menghasilkan butir soal pilihan ganda secara otomatis menggunakan Gemini AI
 */
function generateQuestionsWithGemini(username, token, params) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid. Silakan login kembali.' };
  }
  
  try {
    var ssSettings = getSettings();
    var apiKey = (ssSettings.settings && ssSettings.settings.gemini_api_key) ? ssSettings.settings.gemini_api_key.trim() : '';
    var modelId = (ssSettings.settings && ssSettings.settings.gemini_model) ? ssSettings.settings.gemini_model.trim() : 'gemini-2.0-flash';
    modelId = modelId.replace(/^models\//, '');
    
    if (!apiKey) {
      return { 
        success: false, 
        message: 'API Key Gemini belum disetting. Silakan minta Administrator untuk mengatur API Key di menu Pengaturan Aplikasi.' 
      };
    }
    
    params = params || {};
    var mapel = toSafeString(params.mata_pelajaran || 'Umum');
    var kelas = toSafeString(params.kelas || 'Umum');
    var topik = toSafeString(params.topik || 'Umum');
    var jmlOpsi = Math.max(3, Math.min(10, parseInt(params.jumlah_opsi, 10) || 5));
    var jmlSoal = Math.max(1, Math.min(20, parseInt(params.jumlah_soal, 10) || 5));
    var kesulitan = toSafeString(params.tingkat_kesulitan || 'Sedang');
    
    var alphabet = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
    var activeLetters = alphabet.slice(0, jmlOpsi);
    var opsiStr = activeLetters.join(', ');
    var lastLetter = activeLetters[activeLetters.length - 1];
    
    var prompt = 
      'Anda adalah guru ahli dan pembuat soal evaluasi profesional di Indonesia.\n' +
      'Tugas Anda adalah membuat ' + jmlSoal + ' butir soal pilihan ganda berkualitas tinggi dengan ketentuan berikut:\n' +
      '- Mata Pelajaran: ' + mapel + '\n' +
      '- Tingkat / Kelas: ' + kelas + '\n' +
      '- Topik / Materi: ' + topik + '\n' +
      '- Tingkat Kesulitan: ' + kesulitan + '\n' +
      '- Jumlah Pilihan Opsi per Soal: ' + jmlOpsi + ' opsi (' + opsiStr + ')\n\n' +
      'Petunjuk Pembuatan Soal:\n' +
      '1. Soal harus jelas, tidak berbelit-belit, dan menguji pemahaman konsep atau penalaran sesuai tingkat kesulitan ' + kesulitan + '.\n' +
      '2. Setiap butir soal HARUS memiliki tepat ' + jmlOpsi + ' pilihan jawaban (opsi_a sampai opsi_' + lastLetter.toLowerCase() + ').\n' +
      '3. Pilihan pengecoh (distractor) harus masuk akal, logis, dan mendidik.\n' +
      '4. Kunci jawaban HARUS tepat salah satu dari huruf: ' + opsiStr + '.\n' +
      '5. Output WAJIB HANYA berupa JSON Array murni tanpa tanda kutip markdown (```json) dan tanpa teks pembuka/penutup.\n\n' +
      'Struktur JSON yang WAJIB dipatuhi:\n' +
      '[\n' +
      '  {\n' +
      '    "teks_soal": "Pertanyaan soal...",\n';
    
    for (var i = 0; i < jmlOpsi; i++) {
      prompt += '    "opsi_' + alphabet[i].toLowerCase() + '": "Pilihan teks ' + alphabet[i] + '...",\n';
    }
    prompt += '    "kunci_jawaban": "A"\n  }\n]';
    
    var url = 'https://generativelanguage.googleapis.com/v1beta/models/' + encodeURIComponent(modelId) + ':generateContent?key=' + encodeURIComponent(apiKey);
    var payload = {
      contents: [
        {
          parts: [{ text: prompt }]
        }
      ],
      generationConfig: {
        temperature: 0.7,
        responseMimeType: 'application/json'
      }
    };
    
    var response = UrlFetchApp.fetch(url, {
      method: 'post',
      contentType: 'application/json',
      payload: JSON.stringify(payload),
      muteHttpExceptions: true
    });
    
    var code = response.getResponseCode();
    var contentText = response.getContentText();
    
    if (code !== 200) {
      var errJson = {};
      try { errJson = JSON.parse(contentText); } catch (e) {}
      var errDetail = (errJson.error && errJson.error.message) ? errJson.error.message : contentText;
      return { success: false, message: 'Gemini API Error (' + code + '): ' + errDetail };
    }
    
    var respData = JSON.parse(contentText);
    if (!respData.candidates || !respData.candidates[0] || !respData.candidates[0].content || !respData.candidates[0].content.parts || !respData.candidates[0].content.parts[0]) {
      return { success: false, message: 'Respon dari Gemini tidak memiliki konten jawaban yang valid.' };
    }
    
    var rawAnswer = respData.candidates[0].content.parts[0].text;
    var cleanedJson = rawAnswer.replace(/^```json\s*/i, '').replace(/^```\s*/i, '').replace(/\s*```$/i, '').trim();
    
    var parsedQuestions;
    try {
      parsedQuestions = JSON.parse(cleanedJson);
    } catch (parseErr) {
      return { 
        success: false, 
        message: 'Gagal mem-parsing format JSON hasil Gemini: ' + parseErr.message,
        raw: rawAnswer 
      };
    }
    
    if (!Array.isArray(parsedQuestions)) {
      if (parsedQuestions && typeof parsedQuestions === 'object' && Array.isArray(parsedQuestions.questions)) {
        parsedQuestions = parsedQuestions.questions;
      } else if (parsedQuestions && typeof parsedQuestions === 'object' && Array.isArray(parsedQuestions.soal)) {
        parsedQuestions = parsedQuestions.soal;
      } else {
        return { success: false, message: 'Format keluaran AI bukan berupa daftar (array) butir soal.' };
      }
    }
    
    // Normalisasi struktur butir soal
    var sanitizedQuestions = [];
    for (var qIdx = 0; qIdx < parsedQuestions.length; qIdx++) {
      var q = parsedQuestions[qIdx];
      if (!q || !q.teks_soal) continue;
      
      var cleanQ = {
        teks_soal: toSafeString(q.teks_soal),
        url_gambar: toSafeString(q.url_gambar || ''),
        kunci_jawaban: toSafeString(q.kunci_jawaban || 'A').toUpperCase(),
        bobot: 1
      };
      
      for (var oIdx = 0; oIdx < jmlOpsi; oIdx++) {
        var letter = alphabet[oIdx].toLowerCase();
        var oKey = 'opsi_' + letter;
        cleanQ[oKey] = toSafeString(q[oKey] || q['opsi' + letter.toUpperCase()] || '');
      }
      
      sanitizedQuestions.push(cleanQ);
    }
    
    if (sanitizedQuestions.length === 0) {
      return { success: false, message: 'Tidak ada butir soal yang berhasil diproses dari hasil AI.' };
    }
    
    return {
      success: true,
      questions: sanitizedQuestions,
      modelUsed: modelId,
      count: sanitizedQuestions.length
    };
  } catch (ex) {
    return { success: false, message: 'Terjadi kesalahan sistem saat generate soal: ' + ex.message };
  }
}

/**
 * Menyimpan banyak butir soal sekaligus (hasil generate AI setelah direview)
 * Setiap butir soal secara otomatis memiliki bobot = 1 sesuai ketentuan
 */
function saveBulkQuestions(username, token, id_ujian, questionsList) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid.' };
  }
  if (!id_ujian || !questionsList || !Array.isArray(questionsList) || questionsList.length === 0) {
    return { success: false, message: 'Data soal tidak valid atau kosong.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(15000);
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.QUESTIONS);
    if (!sheet) {
      return { success: false, message: 'Sheet Questions tidak ditemukan.' };
    }
    
    // Pastikan seluruh kolom target ada di sheet
    var qMap = getHeaderMap(sheet);
    var targetHeaders = SHEET_HEADERS.Questions;
    var headerAdded = false;
    targetHeaders.forEach(function(th) {
      if (qMap[th.toLowerCase()] === undefined) {
        var newCol = sheet.getLastColumn() + 1;
        sheet.getRange(1, newCol).setValue(th).setFontWeight('bold').setBackground('#1e293b').setFontColor('#ffffff');
        headerAdded = true;
      }
    });
    if (headerAdded) {
      SpreadsheetApp.flush();
      qMap = getHeaderMap(sheet);
    }
    
    var letters = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];
    var lastCol = sheet.getLastColumn();
    var rowsToAdd = [];
    
    for (var i = 0; i < questionsList.length; i++) {
      var q = questionsList[i];
      var newQId = 'QST-' + Utilities.getUuid().substring(0, 8).toUpperCase();
      var newRow = new Array(lastCol);
      for (var c = 0; c < lastCol; c++) newRow[c] = '';
      
      if (qMap['id_soal'] !== undefined) newRow[qMap['id_soal']] = newQId;
      if (qMap['id_ujian'] !== undefined) newRow[qMap['id_ujian']] = toSafeString(id_ujian);
      if (qMap['teks_soal'] !== undefined) newRow[qMap['teks_soal']] = toSafeString(q.teks_soal);
      if (qMap['url_gambar'] !== undefined) newRow[qMap['url_gambar']] = toSafeString(q.url_gambar || '');
      
      letters.forEach(function(l) {
        var key = 'opsi_' + l;
        if (qMap[key] !== undefined) {
          newRow[qMap[key]] = toSafeString(q[key] || '');
        }
      });
      
      if (qMap['kunci_jawaban'] !== undefined) newRow[qMap['kunci_jawaban']] = toSafeString(q.kunci_jawaban || 'A').toUpperCase();
      if (qMap['bobot'] !== undefined) newRow[qMap['bobot']] = 1; // Poin otomatis 1 sesuai permintaan user
      if (qMap['pembahasan'] !== undefined) newRow[qMap['pembahasan']] = toSafeString(q.pembahasan || '');
      
      rowsToAdd.push(newRow);
    }
    
    if (rowsToAdd.length > 0) {
      var startRow = sheet.getLastRow() + 1;
      var newRange = sheet.getRange(startRow, 1, rowsToAdd.length, lastCol);
      newRange.setNumberFormat('@');
      newRange.setValues(rowsToAdd);
      SpreadsheetApp.flush();
    }
    
    logActivity(username, 'AI Generate Questions', 'Berhasil menambahkan ' + rowsToAdd.length + ' butir soal AI ke ujian ' + id_ujian);
    return { success: true, count: rowsToAdd.length, message: 'Berhasil menyimpan ' + rowsToAdd.length + ' butir soal ke bank soal.' };
  } catch (err) {
    return { success: false, message: 'Gagal menyimpan soal AI: ' + err.message };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Normalisasi opsi "Jumlah Percobaan" sebuah ujian.
 * Default 1 (dikerjakan sekali), minimum 1, maksimum 99.
 */
function normalizeExamAttempts(value) {
  var n = parseInt(value, 10);
  if (isNaN(n) || n < 1) return 1;
  if (n > 99) return 99;
  return n;
}

/**
 * Normalisasi batas maksimal pelanggaran sebuah ujian (per-ujian).
 * Default 3, minimum 1, maksimum 99.
 */
function normalizeExamMaxViolations(value) {
  var n = parseInt(value, 10);
  if (isNaN(n) || n < 1) return 3;
  if (n > 99) return 99;
  return n;
}

/**
 * Normalisasi tindakan saat batas maksimal pelanggaran tercapai (per-ujian).
 * Nilai valid: auto_submit (default), auto_blokir, record_only (hanya catat).
 */
function normalizeViolationAction(value) {
  var v = toSafeString(value).trim().toLowerCase();
  if (v === 'auto_blokir') return 'auto_blokir';
  if (v === 'record_only') return 'record_only';
  return 'auto_submit';
}

/**
 * Mendapatkan nomor percobaan (1-based) dari satu baris sheet Results.
 * Baris lama yang belum memiliki nilai percobaan_ke dianggap percobaan ke-1.
 */
function getResultAttemptNumber(row, attemptColIdx) {
  if (!row || attemptColIdx === undefined || attemptColIdx === -1 || row[attemptColIdx] === undefined) return 1;
  return normalizeExamAttempts(row[attemptColIdx]);
}

/**
 * Meringkas riwayat percobaan siswa pada satu ujian.
 * Setiap baris Results = satu percobaan. Duplikat pada percobaan yang sama dipilih
 * yang terbaik (tanpa menghapus baris) sehingga nilai tertinggi tetap terpakai.
 *
 * @param {Array} attemptRows daftar { attempt, status, score, ... }
 * @param {number|string} limit jumlah percobaan maksimal ujian
 * @returns {object} { rows, attempts_used, attempts_limit, attempts_left, best, draft, is_blocked, is_complete }
 */
function summarizeStudentAttempts(attemptRows, limit) {
  var limitN = normalizeExamAttempts(limit);
  var byAttempt = {};
  (attemptRows || []).forEach(function(r) {
    if (!r) return;
    var key = String(normalizeExamAttempts(r.attempt));
    var cur = byAttempt[key];
    if (!cur) {
      byAttempt[key] = r;
      return;
    }
    if (r.status === 'SUBMITTED' && cur.status !== 'SUBMITTED') {
      byAttempt[key] = r;
      return;
    }
    if (r.status === cur.status && Number(r.score) > Number(cur.score)) byAttempt[key] = r;
  });

  var rows = [];
  Object.keys(byAttempt).forEach(function(k) { rows.push(byAttempt[k]); });
  rows.sort(function(a, b) { return Number(a.attempt) - Number(b.attempt); });

  var best = null;
  var draft = null;
  var blocked = null;
  rows.forEach(function(r) {
    if (r.status === 'SUBMITTED' && (!best || Number(r.score) > Number(best.score))) best = r;
    if (r.status === 'DRAFT') draft = r;
    if (r.status === 'TERBLOKIR') blocked = r;
  });

  var used = rows.length;
  return {
    rows: rows,
    attempts_used: used,
    attempts_limit: limitN,
    attempts_left: Math.max(0, limitN - used),
    best: best,
    draft: draft,
    is_blocked: Boolean(blocked),
    is_complete: used >= limitN && Boolean(best) && !draft && !blocked
  };
}

/**
 * Mencari atau mengonsolidasi baris pengerjaan ujian siswa di sheet Results.
 * Duplikat HANYA dihapus bila berada pada percobaan yang sama (misal akibat autosave
 * berulang). Percobaan berbeda dibiarkan agar nilai tertinggi dapat diambil.
 *
 * @param {Sheet} resultsSheet
 * @param {string} id_ujian
 * @param {string} username
 * @param {number} [percobaanKe] nomor percobaan yang dituju. Jika kosong, dipakai
 *        percobaan terakhir (nomor tertinggi) milik siswa.
 * @returns {object|null} { rowNumber, data, id_hasil, status, percobaan_ke, total_percobaan, nilai_tertinggi }
 */
function findAndConsolidateStudentResult(resultsSheet, id_ujian, username, percobaanKe) {
  if (!resultsSheet) return null;
  var data = resultsSheet.getDataRange().getValues();
  if (data.length <= 1) return null;
  
  var targetExamId = toSafeString(id_ujian).trim();
  var targetUsername = toSafeString(username).trim().toLowerCase();
  if (!targetExamId || !targetUsername) return null;

  var map = getHeaderMap(resultsSheet);
  var examColIdx = map['id_ujian'] !== undefined ? map['id_ujian'] : 1;
  var userColIdx = map['username_siswa'] !== undefined ? map['username_siswa'] : 2;
  var scoreColIdx = map['nilai_akhir'] !== undefined ? map['nilai_akhir'] : 6;
  var statusColIdx = map['status'] !== undefined ? map['status'] : 7;
  var attemptColIdx = map['percobaan_ke'] !== undefined ? map['percobaan_ke'] : -1;
  
  var matched = []; // array of { rowNumber: i + 1, attempt, status, score, data: data[i] }
  for (var i = 1; i < data.length; i++) {
    var rowExamId = toSafeString(data[i][examColIdx]).trim();
    var rowUsername = toSafeString(data[i][userColIdx]).trim().toLowerCase();
    if (rowExamId === targetExamId && rowUsername === targetUsername) {
      matched.push({
        rowNumber: i + 1,
        attempt: getResultAttemptNumber(data[i], attemptColIdx),
        status: toSafeString(data[i][statusColIdx]).trim().toUpperCase(),
        score: Number(data[i][scoreColIdx]) || 0,
        data: data[i]
      });
    }
  }
  
  if (matched.length === 0) return null;

  // Tentukan percobaan yang dituju (parameter, atau percobaan terakhir siswa)
  var targetAttempt = null;
  if (percobaanKe !== undefined && percobaanKe !== null && toSafeString(percobaanKe).trim() !== '') {
    targetAttempt = normalizeExamAttempts(percobaanKe);
  } else {
    matched.forEach(function(r) {
      if (targetAttempt === null || r.attempt > targetAttempt) targetAttempt = r.attempt;
    });
  }

  // Hanya bereskan duplikat BARIS pada percobaan yang sama (pilih yang terbaik)
  var grouped = {};
  matched.forEach(function(r) {
    var key = String(r.attempt);
    if (!grouped[key]) grouped[key] = [];
    grouped[key].push(r);
  });

  var rowsToDelete = [];
  Object.keys(grouped).forEach(function(k) {
    var group = grouped[k];
    var chosen = group[0];
    for (var g = 1; g < group.length; g++) {
      var cand = group[g];
      if (cand.status === 'SUBMITTED' && chosen.status !== 'SUBMITTED') {
        chosen = cand;
      } else if (cand.status === chosen.status && cand.score > chosen.score) {
        chosen = cand;
      }
    }
    group.forEach(function(r) {
      if (r.rowNumber !== chosen.rowNumber) rowsToDelete.push(r.rowNumber);
    });
  });
  
  if (rowsToDelete.length > 0) {
    rowsToDelete.sort(function(a, b) { return b - a; });
    for (var d = 0; d < rowsToDelete.length; d++) {
      try {
        resultsSheet.deleteRow(rowsToDelete[d]);
      } catch (e) {
        console.error('Gagal menghapus baris duplikat Results: ', e);
      }
    }
  }

  // Baca ulang (row number bisa bergeser setelah penghapusan) lalu susun ringkasan
  var freshData = resultsSheet.getDataRange().getValues();
  var targetRow = -1;
  var totalPercobaan = 0;
  var attemptSet = {};
  var nilaiTertinggi = 0;
  for (var k = 1; k < freshData.length; k++) {
    var kExamId = toSafeString(freshData[k][examColIdx]).trim();
    var kUsername = toSafeString(freshData[k][userColIdx]).trim().toLowerCase();
    if (kExamId !== targetExamId || kUsername !== targetUsername) continue;

    var kAttempt = getResultAttemptNumber(freshData[k], attemptColIdx);
    if (!attemptSet[String(kAttempt)]) {
      attemptSet[String(kAttempt)] = true;
      totalPercobaan++;
    }
    var kStatus = toSafeString(freshData[k][statusColIdx]).trim().toUpperCase();
    var kScore = Number(freshData[k][scoreColIdx]) || 0;
    if (kStatus === 'SUBMITTED' && kScore > nilaiTertinggi) nilaiTertinggi = kScore;
    if (kAttempt === targetAttempt && targetRow === -1) targetRow = k + 1;
  }

  if (targetRow === -1) return null;

  return {
    rowNumber: targetRow,
    id_hasil: toSafeString(freshData[targetRow - 1][0]),
    status: toSafeString(freshData[targetRow - 1][statusColIdx]).trim().toUpperCase(),
    data: freshData[targetRow - 1],
    percobaan_ke: targetAttempt,
    total_percobaan: totalPercobaan,
    nilai_tertinggi: nilaiTertinggi
  };
}


// REKAPITULASI NILAI GURU (Dengan Deduping Otomatis & Serialisasi Aman)
function getExamResults(username, token, id_ujian) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid.' };
  }
  
  try {
    var ss = getSpreadsheet();
    var resultsSheet = getSheetSafe(ss, SHEETS.RESULTS);
    var usersSheet = getSheetSafe(ss, SHEETS.USERS);
    
    if (!resultsSheet) {
      return { success: true, results: [] };
    }
    
    var resultsData = resultsSheet.getDataRange().getValues();
    var usersData = usersSheet ? usersSheet.getDataRange().getValues() : [];
    
    // Map data pengguna (case-insensitive username)
    var usersMap = {};
    var uSesiIdx = -1;
    if (usersSheet) {
      var uHeaderMap = getHeaderMap(usersSheet);
      uSesiIdx = uHeaderMap['sesi'] !== undefined ? uHeaderMap['sesi'] : -1;
    }
    for (var i = 1; i < usersData.length; i++) {
      var uNameKey = toSafeString(usersData[i][1]).toLowerCase();
      if (uNameKey) {
        usersMap[uNameKey] = {
          nama_lengkap: toSafeString(usersData[i][3]) || toSafeString(usersData[i][1]),
          kelas: toSafeString(usersData[i][5]) || '-',
          sesi: uSesiIdx !== -1 ? (toSafeString(usersData[i][uSesiIdx]) || '-') : '-'
        };
      }
    }
    
    var targetExamId = toSafeString(id_ujian).trim();

    // Indeks kolom paket soal acak milik masing-masing siswa (Results.soal_terpilih)
    var rHeaderMap = getHeaderMap(resultsSheet);
    var rSoalTerpilihIdx = rHeaderMap['soal_terpilih'] !== undefined ? rHeaderMap['soal_terpilih'] : -1;

    // Verifikasi hak akses jika pemanggil adalah guru
    var callerRole = '';
    var uRoleCol = uHeaderMap && uHeaderMap['role'] !== undefined ? uHeaderMap['role'] : 4;
    for (var uCheck = 1; uCheck < usersData.length; uCheck++) {
      if (toSafeString(usersData[uCheck][1]).toLowerCase() === username.toLowerCase()) {
        callerRole = toSafeString(usersData[uCheck][uRoleCol]).toLowerCase();
        break;
      }
    }

    if (callerRole === 'guru' && targetExamId) {
      var examsSheetCheck = getSheetSafe(ss, SHEETS.EXAMS);
      if (examsSheetCheck) {
        var eCheckData = examsSheetCheck.getDataRange().getValues();
        var eCheckMap = getHeaderMap(examsSheetCheck);
        var ecIdCol = eCheckMap['id_ujian'] !== undefined ? eCheckMap['id_ujian'] : 0;
        var ecGuruCol = eCheckMap['id_guru'] !== undefined ? eCheckMap['id_guru'] : 9;
        var ecKolabCol = eCheckMap['kolaborator'] !== undefined ? eCheckMap['kolaborator'] : -1;
        var hasAccess = false;
        for (var ec = 1; ec < eCheckData.length; ec++) {
          if (toSafeString(eCheckData[ec][ecIdCol]) === targetExamId) {
            var ecOwner = toSafeString(eCheckData[ec][ecGuruCol]).trim().toLowerCase();
            var ecKolab = ecKolabCol !== -1 ? toSafeString(eCheckData[ec][ecKolabCol]).trim().toLowerCase() : '';
            var ecKolabList = ecKolab ? ecKolab.split(',').map(function(s) { return s.trim(); }).filter(Boolean) : [];
            if (ecOwner === username.toLowerCase() || ecKolabList.indexOf(username.toLowerCase()) !== -1 || !ecOwner) {
              hasAccess = true;
            }
            break;
          }
        }
        if (!hasAccess) {
          return { success: false, message: 'Anda tidak memiliki hak akses untuk melihat rekap ujian ini.' };
        }
      }
    }

    var attemptColIdx = rHeaderMap['percobaan_ke'] !== undefined ? rHeaderMap['percobaan_ke'] : -1;

    // { [uname]: { attempts: { [percobaanKe]: recordObj } } }
    // Setiap percobaan dikelompokkan terpisah, duplikat hanya dibersihkan dalam
    // percobaan yang sama. Nilai yang dipakai adalah nilai TERTINGGI antar percobaan.
    var studentGrouped = {};
    var duplicatesToDelete = []; // row indices to clean up
    
    for (var j = 1; j < resultsData.length; j++) {
      var row = resultsData[j];
      var rowExamId = toSafeString(row[1]).trim();
      if (!targetExamId || rowExamId === targetExamId) {
        var userUname = toSafeString(row[2]).trim();
        var userUnameKey = userUname.toLowerCase();
        if (!userUnameKey) continue;
        
        var uInfo = usersMap[userUnameKey] || { nama_lengkap: userUname, kelas: '-', sesi: '-' };
        var currentStatus = toSafeString(row[7]);
        var currentScore = Number(row[6]) || 0;
        var attemptNo = getResultAttemptNumber(row, attemptColIdx);
        
        var recordObj = {
          rowNumber: j + 1,
          id_hasil: toSafeString(row[0]),
          id_ujian: rowExamId,
          username_siswa: userUname,
          nama_siswa: uInfo.nama_lengkap,
          kelas: uInfo.kelas,
          sesi: uInfo.sesi,
          waktu_mulai: toSafeString(row[3]),
          waktu_submit: toSafeString(row[4]),
          jumlah_pelanggaran: Number(row[5]) || 0,
          nilai_akhir: currentScore,
          status: currentStatus,
          detail_jawaban: toSafeString(row[8]),
          soal_terpilih: rSoalTerpilihIdx !== -1 ? toSafeString(row[rSoalTerpilihIdx]) : '',
          percobaan_ke: attemptNo
        };

        if (!studentGrouped[userUnameKey]) studentGrouped[userUnameKey] = { attempts: {} };
        var attemptKey = String(attemptNo);
        var existingAttempt = studentGrouped[userUnameKey].attempts[attemptKey];

        if (!existingAttempt) {
          studentGrouped[userUnameKey].attempts[attemptKey] = recordObj;
        } else if (currentStatus === 'SUBMITTED' && existingAttempt.status !== 'SUBMITTED') {
          duplicatesToDelete.push(existingAttempt.rowNumber);
          studentGrouped[userUnameKey].attempts[attemptKey] = recordObj;
        } else if (currentStatus === existingAttempt.status && currentScore > existingAttempt.nilai_akhir) {
          duplicatesToDelete.push(existingAttempt.rowNumber);
          studentGrouped[userUnameKey].attempts[attemptKey] = recordObj;
        } else {
          duplicatesToDelete.push(j + 1);
        }
      }
    }
    
    // Bersihkan baris-baris duplikat dari sheet (dari urutan baris terbesar ke terkecil)
    if (duplicatesToDelete.length > 0) {
      duplicatesToDelete.sort(function(a, b) { return b - a; });
      for (var d = 0; d < duplicatesToDelete.length; d++) {
        try {
          resultsSheet.deleteRow(duplicatesToDelete[d]);
        } catch (e) {
          console.error('Error auto-cleaning duplicate row: ', e);
        }
      }
    }
    
    var results = [];
    for (var uname in studentGrouped) {
      var attemptMap = studentGrouped[uname].attempts;
      var bestItem = null;
      var attemptCount = 0;
      var totalViolations = 0;
      var violationsPerAttempt = [];
      for (var atKey in attemptMap) {
        var cand = attemptMap[atKey];
        attemptCount++;
        totalViolations += Number(cand.jumlah_pelanggaran) || 0;
        violationsPerAttempt.push({
          percobaan_ke: Number(cand.percobaan_ke) || 1,
          jumlah_pelanggaran: Number(cand.jumlah_pelanggaran) || 0
        });
        if (!bestItem) {
          bestItem = cand;
        } else if (cand.status === 'SUBMITTED' && bestItem.status !== 'SUBMITTED') {
          bestItem = cand;
        } else if (cand.status === bestItem.status && Number(cand.nilai_akhir) > Number(bestItem.nilai_akhir)) {
          bestItem = cand;
        }
      }
      if (!bestItem) continue;

      violationsPerAttempt.sort(function(a, b) { return a.percobaan_ke - b.percobaan_ke; });

      results.push({
        id_hasil: bestItem.id_hasil,
        id_ujian: bestItem.id_ujian,
        username_siswa: bestItem.username_siswa,
        nama_siswa: bestItem.nama_siswa,
        kelas: bestItem.kelas,
        sesi: bestItem.sesi || '-',
        waktu_mulai: bestItem.waktu_mulai,
        waktu_submit: bestItem.waktu_submit,
        // Pelanggaran = TOTAL seluruh percobaan (metrik kedisiplinan), sedangkan
        // nilai/status mengikuti percobaan terbaik. Rincian per percobaan ikut
        // dikirim agar konsisten dengan modal riwayat pelanggaran.
        jumlah_pelanggaran: totalViolations,
        pelanggaran_percobaan: violationsPerAttempt,
        nilai_akhir: bestItem.nilai_akhir,
        nilai_tertinggi: bestItem.nilai_akhir,
        status: bestItem.status,
        detail_jawaban: bestItem.detail_jawaban,
        soal_terpilih: bestItem.soal_terpilih || '',
        percobaan_ke: bestItem.percobaan_ke,
        jumlah_percobaan_dilakukan: attemptCount
      });
    }
    
    var examInfo = { id_ujian: targetExamId, nama_ujian: targetExamId, kelas: '', sesi: '', tipe_peserta: 'general', peserta_khusus: '', kolaborator: '', tingkat: '', jumlah_percobaan: 1 };
    var examsSheet = getSheetSafe(ss, SHEETS.EXAMS);
    if (examsSheet && targetExamId) {
      var eData = examsSheet.getDataRange().getValues();
      var eMap = getHeaderMap(examsSheet);
      var eIdCol = eMap['id_ujian'] !== undefined ? eMap['id_ujian'] : 0;
      var eNameCol = eMap['nama_ujian'] !== undefined ? eMap['nama_ujian'] : 1;
      var eKelasCol = eMap['kelas'] !== undefined ? eMap['kelas'] : -1;
      var eSesiCol = eMap['sesi'] !== undefined ? eMap['sesi'] : -1;
      var eTipeCol = eMap['tipe_peserta'] !== undefined ? eMap['tipe_peserta'] : -1;
      var ePesertaCol = eMap['peserta_khusus'] !== undefined ? eMap['peserta_khusus'] : -1;
      var eKolabCol = eMap['kolaborator'] !== undefined ? eMap['kolaborator'] : -1;
      var eTktCol = eMap['tingkat'] !== undefined ? eMap['tingkat'] : -1;
      var ePercobaanCol = eMap['jumlah_percobaan'] !== undefined ? eMap['jumlah_percobaan'] : -1;
      for (var e = 1; e < eData.length; e++) {
        if (toSafeString(eData[e][eIdCol]) === targetExamId) {
          examInfo.nama_ujian = toSafeString(eData[e][eNameCol]) || targetExamId;
          examInfo.kelas = eKelasCol !== -1 ? toSafeString(eData[e][eKelasCol]) : '';
          examInfo.sesi = eSesiCol !== -1 ? toSafeString(eData[e][eSesiCol]) : '';
          examInfo.tipe_peserta = eTipeCol !== -1 && toSafeString(eData[e][eTipeCol]) ? toSafeString(eData[e][eTipeCol]).trim().toLowerCase() : 'general';
          examInfo.peserta_khusus = ePesertaCol !== -1 ? toSafeString(eData[e][ePesertaCol]) : '';
          examInfo.kolaborator = eKolabCol !== -1 ? toSafeString(eData[e][eKolabCol]) : '';
          examInfo.tingkat = eTktCol !== -1 ? toSafeString(eData[e][eTktCol]) : '';
          examInfo.jumlah_percobaan = ePercobaanCol !== -1 ? normalizeExamAttempts(eData[e][ePercobaanCol]) : 1;
          break;
        }
      }
    }

    // Lengkapi setiap peserta dengan batas jumlah percobaan ujian
    results.forEach(function(rItem) { rItem.jumlah_percobaan = examInfo.jumlah_percobaan; });

    // Ambil seluruh butir soal ujian ini untuk keperluan review/koreksi jawaban siswa
    var examQuestions = [];
    var questionsSheet = getSheetSafe(ss, SHEETS.QUESTIONS);
    if (questionsSheet && targetExamId) {
      var qData = questionsSheet.getDataRange().getDisplayValues();
      var qMap = getHeaderMap(questionsSheet);
      var qIdCol = qMap['id_soal'] !== undefined ? qMap['id_soal'] : 0;
      var qExamCol = qMap['id_ujian'] !== undefined ? qMap['id_ujian'] : 1;
      var qTeksCol = qMap['teks_soal'] !== undefined ? qMap['teks_soal'] : 2;
      var qImgCol = qMap['url_gambar'] !== undefined ? qMap['url_gambar'] : -1;
      var qKeyCol = qMap['kunci_jawaban'] !== undefined ? qMap['kunci_jawaban'] : 8;
      var qBobotCol = qMap['bobot'] !== undefined ? qMap['bobot'] : 9;
      var qIdTeksCol = qMap['id_teks'] !== undefined ? qMap['id_teks'] : -1;
      var letters = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];
      var textMap = getExamTextMap(ss, targetExamId);

      for (var q = 1; q < qData.length; q++) {
        if (toSafeString(qData[q][qExamCol]).trim() === targetExamId) {
          var qIdTeks = qIdTeksCol !== -1 ? toSafeString(qData[q][qIdTeksCol]).trim() : '';
          var qTeksObj = textMap[qIdTeks] || null;
          var qObj = {
            id_soal: toSafeString(qData[q][qIdCol]),
            teks_soal: toSafeString(qData[q][qTeksCol]),
            url_gambar: qImgCol !== -1 ? toSafeString(qData[q][qImgCol]) : '',
            kunci_jawaban: toSafeString(qData[q][qKeyCol]).trim().toUpperCase(),
            bobot: Number(qData[q][qBobotCol]) || 1,
            id_teks: qIdTeks,
            nama_teks: qTeksObj ? qTeksObj.nama_teks : '',
            teks_konteks: qTeksObj ? qTeksObj.isi_teks : ''
          };
          letters.forEach(function(l) {
            var key = 'opsi_' + l;
            qObj[key] = qMap[key] !== undefined ? toSafeString(qData[q][qMap[key]]) : '';
          });
          examQuestions.push(qObj);
        }
      }
    }
    
    return { success: true, results: results, exam: examInfo, questions: examQuestions };
  } catch (err) {
    return { success: false, message: 'Gagal memuat rekapitulasi nilai: ' + err.message };
  }
}

/**
 * Menghapus data pengerjaan ujian seorang siswa tertentu dari sheet Results
 */
function deleteStudentExamResult(username, token, id_ujian, username_siswa) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid.' };
  }
  
  var targetExamId = toSafeString(id_ujian).trim();
  var targetUser = toSafeString(username_siswa).trim().toLowerCase();
  
  if (!targetExamId || !targetUser) {
    return { success: false, message: 'Data ujian atau siswa tidak valid.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(15000);
  try {
    var ss = getSpreadsheet();
    var resultsSheet = getSheetSafe(ss, SHEETS.RESULTS);
    if (!resultsSheet) {
      return { success: false, message: 'Sheet Results tidak ditemukan.' };
    }
    
    var data = resultsSheet.getDataRange().getValues();
    var rowsToDelete = [];
    for (var i = 1; i < data.length; i++) {
      var rowExamId = toSafeString(data[i][1]).trim();
      var rowUser = toSafeString(data[i][2]).trim().toLowerCase();
      if (rowExamId === targetExamId && rowUser === targetUser) {
        rowsToDelete.push(i + 1);
      }
    }
    
    if (rowsToDelete.length === 0) {
      return { success: false, message: 'Data hasil ujian siswa tidak ditemukan.' };
    }
    
    // Hapus baris dari nomor baris terbesar ke terkecil
    rowsToDelete.sort(function(a, b) { return b - a; });
    for (var d = 0; d < rowsToDelete.length; d++) {
      resultsSheet.deleteRow(rowsToDelete[d]);
    }
    SpreadsheetApp.flush();
    
    logActivity(username, 'Delete Student Result', 'Menghapus data hasil ujian siswa ' + targetUser + ' pada ujian ' + targetExamId);
    return { success: true, message: 'Data hasil ujian siswa berhasil dihapus.' };
  } catch (err) {
    return { success: false, message: 'Gagal menghapus data ujian siswa: ' + err.message };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Mengambil riwayat rincian pelanggaran seorang siswa pada ujian tertentu dari sheet Logs
 */
function getStudentViolations(username, token, id_ujian, username_siswa) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid.' };
  }
  
  var targetExamId = toSafeString(id_ujian).trim();
  var targetUser = toSafeString(username_siswa).trim().toLowerCase();
  
  if (!targetExamId || !targetUser) {
    return { success: false, message: 'Data ujian atau siswa tidak valid.' };
  }

  try {
    var ss = getSpreadsheet();
    var logsSheet = getSheetSafe(ss, SHEETS.LOGS);
    var resultsSheet = getSheetSafe(ss, SHEETS.RESULTS);
    
    // Total pelanggaran = penjumlahan seluruh percobaan siswa pada ujian ini,
    // sehingga angkanya sama dengan yang tampil pada tabel rekap (bukan hanya
    // percobaan pertama atau percobaan dengan nilai tertinggi).
    var recordedViolations = 0;
    var perAttempt = [];
    if (resultsSheet) {
      var resData = resultsSheet.getDataRange().getValues();
      var rvMap = getHeaderMap(resultsSheet);
      var rvExamCol = rvMap['id_ujian'] !== undefined ? rvMap['id_ujian'] : 1;
      var rvUserCol = rvMap['username_siswa'] !== undefined ? rvMap['username_siswa'] : 2;
      var rvViolCol = rvMap['jumlah_pelanggaran'] !== undefined ? rvMap['jumlah_pelanggaran'] : 5;
      var rvAttemptCol = rvMap['percobaan_ke'] !== undefined ? rvMap['percobaan_ke'] : -1;

      var attemptViolMap = {};
      for (var r = 1; r < resData.length; r++) {
        if (toSafeString(resData[r][rvExamCol]).trim() === targetExamId &&
            toSafeString(resData[r][rvUserCol]).trim().toLowerCase() === targetUser) {
          var atNo = getResultAttemptNumber(resData[r], rvAttemptCol);
          var atViol = Number(resData[r][rvViolCol]) || 0;
          if (attemptViolMap[atNo] === undefined || atViol > attemptViolMap[atNo]) {
            attemptViolMap[atNo] = atViol;
          }
        }
      }
      var atKeys = Object.keys(attemptViolMap).map(Number).sort(function(a, b) { return a - b; });
      for (var ak = 0; ak < atKeys.length; ak++) {
        recordedViolations += attemptViolMap[atKeys[ak]];
        perAttempt.push({ percobaan_ke: atKeys[ak], jumlah_pelanggaran: attemptViolMap[atKeys[ak]] });
      }
    }

    var logsList = [];
    if (logsSheet) {
      var logData = logsSheet.getDataRange().getDisplayValues();
      // Format: timestamp (0), username (1), jenis_kejadian (2), keterangan (3)
      for (var i = 1; i < logData.length; i++) {
        var row = logData[i];
        var rowUser = toSafeString(row[1]).trim().toLowerCase();
        if (rowUser === targetUser) {
          var rowJenis = toSafeString(row[2]).trim();
          var rowKet = toSafeString(row[3]).trim();
          
          var isRelatedExam = !targetExamId || rowKet.indexOf(targetExamId) !== -1;
          var isViolation = rowJenis.toLowerCase().indexOf('violation') !== -1 ||
                            rowJenis.toLowerCase().indexOf('tab') !== -1 ||
                            rowJenis.toLowerCase().indexOf('fullscreen') !== -1 ||
                            rowKet.toLowerCase().indexOf('pelanggaran') !== -1 ||
                            (rowJenis.toLowerCase().indexOf('auto submit') !== -1 && rowKet.toLowerCase().indexOf('pelanggaran') !== -1);
                            
          if (isRelatedExam && isViolation) {
            logsList.push({
              timestamp: toSafeString(row[0]),
              jenis: rowJenis,
              keterangan: rowKet
            });
          }
        }
      }
    }

    return {
      success: true,
      totalRecorded: recordedViolations,
      perAttempt: perAttempt,
      violations: logsList
    };
  } catch (err) {
    return { success: false, message: 'Gagal mengambil log pelanggaran: ' + err.message };
  }
}

// ==========================================
// 6b. NILAI ULANG (REGRADE) HASIL UJIAN
// ==========================================

/**
 * Menghitung ulang nilai (nilai_akhir) SELURUH baris Results milik suatu ujian
 * memakai kunci jawaban & bobot soal yang berlaku saat ini.
 *
 * Dipakai ketika guru/admin merevisi kunci jawaban setelah siswa mengumpulkan ujian.
 * Cakupan: semua siswa dan SEMUA percobaan (percobaan_ke 1, 2, 3, ...), termasuk
 * percobaan yang nilainya lebih rendah sehingga tidak tampil pada tabel rekap.
 * Hanya baris berstatus SUBMITTED yang dinilai; DRAFT/TERBLOKIR dibiarkan apa adanya.
 */
function regradeExamResults(username, token, id_ujian) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }

  var targetExamId = toSafeString(id_ujian).trim();
  if (!targetExamId) {
    return { success: false, message: 'ID ujian tidak valid.' };
  }

  var lock = LockService.getScriptLock();
  if (!lock.tryLock(30000)) {
    return { success: false, message: 'Server sedang sibuk. Coba lagi beberapa saat lagi.' };
  }

  try {
    var ss = getSpreadsheet();
    var usersSheet = getSheetSafe(ss, SHEETS.USERS);
    var examsSheet = getSheetSafe(ss, SHEETS.EXAMS);
    var questionsSheet = getSheetSafe(ss, SHEETS.QUESTIONS);
    var resultsSheet = getSheetSafe(ss, SHEETS.RESULTS);

    if (!resultsSheet || !questionsSheet) {
      return { success: false, message: 'Data ujian belum tersedia.' };
    }

    // ---------- 1. Hak akses: hanya Admin / Guru pengampu (atau kolaborator) ----------
    var callerRole = '';
    var callerLower = String(username || '').trim().toLowerCase();
    if (usersSheet) {
      var uMap = getHeaderMap(usersSheet);
      var uRoleCol = uMap['role'] !== undefined ? uMap['role'] : 4;
      var uData = usersSheet.getDataRange().getValues();
      for (var u = 1; u < uData.length; u++) {
        if (toSafeString(uData[u][1]).trim().toLowerCase() === callerLower) {
          callerRole = toSafeString(uData[u][uRoleCol]).trim().toLowerCase();
          break;
        }
      }
    }
    if (callerRole !== 'admin' && callerRole !== 'guru') {
      return { success: false, message: 'Hanya Admin atau Guru yang dapat menilai ulang hasil ujian.' };
    }

    var examName = targetExamId;
    var examFound = false;
    if (examsSheet) {
      var eData = examsSheet.getDataRange().getValues();
      var eMap = getHeaderMap(examsSheet);
      var eIdCol = eMap['id_ujian'] !== undefined ? eMap['id_ujian'] : 0;
      var eNamaCol = eMap['nama_ujian'] !== undefined ? eMap['nama_ujian'] : 1;
      var eGuruCol = eMap['id_guru'] !== undefined ? eMap['id_guru'] : 9;
      var eKolabCol = eMap['kolaborator'] !== undefined ? eMap['kolaborator'] : -1;
      for (var e = 1; e < eData.length; e++) {
        if (toSafeString(eData[e][eIdCol]).trim() === targetExamId) {
          examFound = true;
          examName = toSafeString(eData[e][eNamaCol]) || targetExamId;
          if (callerRole === 'guru') {
            var owner = toSafeString(eData[e][eGuruCol]).trim().toLowerCase();
            var kolabRaw = eKolabCol !== -1 ? toSafeString(eData[e][eKolabCol]).trim().toLowerCase() : '';
            var kolabList = kolabRaw ? kolabRaw.split(',').map(function(s) { return s.trim(); }).filter(Boolean) : [];
            if (owner && owner !== callerLower && kolabList.indexOf(callerLower) === -1) {
              return { success: false, message: 'Anda tidak memiliki hak akses untuk menilai ulang ujian ini.' };
            }
          }
          break;
        }
      }
      if (!examFound) {
        return { success: false, message: 'Ujian tidak ditemukan.' };
      }
    }

    // ---------- 2. Kunci jawaban & bobot terbaru ----------
    var qData = questionsSheet.getDataRange().getDisplayValues();
    var qMapHeader = getHeaderMap(questionsSheet);
    var qIdCol = qMapHeader['id_soal'] !== undefined ? qMapHeader['id_soal'] : 0;
    var qExamCol = qMapHeader['id_ujian'] !== undefined ? qMapHeader['id_ujian'] : 1;
    var qKunciCol = qMapHeader['kunci_jawaban'] !== undefined ? qMapHeader['kunci_jawaban'] : 8;
    var qBobotCol = qMapHeader['bobot'] !== undefined ? qMapHeader['bobot'] : 9;

    var keyMap = {};        // id_soal -> { kunci, bobot }
    var questionOrder = []; // urutan soal ujian ini
    for (var q = 1; q < qData.length; q++) {
      if (toSafeString(qData[q][qExamCol]).trim() === targetExamId) {
        var qId = toSafeString(qData[q][qIdCol]).trim();
        if (!qId) continue;
        keyMap[qId] = {
          kunci: toSafeString(qData[q][qKunciCol]).trim().toUpperCase(),
          bobot: Number(qData[q][qBobotCol]) || 1
        };
        questionOrder.push(qId);
      }
    }
    if (questionOrder.length === 0) {
      return { success: false, message: 'Ujian ini belum memiliki butir soal sehingga tidak dapat dinilai ulang.' };
    }

    // ---------- 3. Hitung ulang tiap baris Results milik ujian ini ----------
    var rData = resultsSheet.getDataRange().getValues();
    var rMap = getHeaderMap(resultsSheet);
    var rExamCol = rMap['id_ujian'] !== undefined ? rMap['id_ujian'] : 1;
    var rUserCol = rMap['username_siswa'] !== undefined ? rMap['username_siswa'] : 2;
    var rScoreCol = rMap['nilai_akhir'] !== undefined ? rMap['nilai_akhir'] : 6;
    var rStatusCol = rMap['status'] !== undefined ? rMap['status'] : 7;
    var rDetailCol = rMap['detail_jawaban'] !== undefined ? rMap['detail_jawaban'] : 8;
    var rSelCol = rMap['soal_terpilih'] !== undefined ? rMap['soal_terpilih'] : 9;

    var updates = [];
    var evaluated = 0;
    var changed = 0;
    var skipped = 0;
    var affectedStudents = {};

    for (var r = 1; r < rData.length; r++) {
      if (toSafeString(rData[r][rExamCol]).trim() !== targetExamId) continue;

      var rowStatus = toSafeString(rData[r][rStatusCol]).trim().toUpperCase();
      if (rowStatus !== 'SUBMITTED') { skipped++; continue; }

      var answers = {};
      var detailRaw = toSafeString(rData[r][rDetailCol]);
      if (detailRaw) {
        try {
          var parsedDetail = JSON.parse(detailRaw);
          if (parsedDetail && typeof parsedDetail === 'object') answers = parsedDetail;
        } catch (errParse) {
          answers = {};
        }
      }

      // Paket soal acak milik siswa. Kosong = ujian tanpa batas "jumlah soal tampil".
      var selectedSet = null;
      var selRaw = rSelCol !== -1 ? toSafeString(rData[r][rSelCol]) : '';
      if (selRaw) {
        var tmpSet = {};
        selRaw.split(',').forEach(function(sidRaw) {
          var sid = String(sidRaw).trim();
          if (sid) tmpSet[sid] = true;
        });
        if (Object.keys(tmpSet).length > 0) selectedSet = tmpSet;
      }

      var totalBobot = 0;
      var skorDiperoleh = 0;
      for (var qi = 0; qi < questionOrder.length; qi++) {
        var soalId = questionOrder[qi];
        if (selectedSet && !selectedSet[soalId]) continue;
        var bobotQ = keyMap[soalId].bobot;
        totalBobot += bobotQ;
        var jawabanSiswa = toSafeString(answers[soalId]).trim().toUpperCase();
        if (jawabanSiswa && jawabanSiswa === keyMap[soalId].kunci) {
          skorDiperoleh += bobotQ;
        }
      }

      // Rumus sama persis dengan submitExam (skala 0-100, satu angka desimal)
      var nilaiBaru = totalBobot > 0 ? Math.round((skorDiperoleh / totalBobot) * 100 * 10) / 10 : 0;
      var nilaiLama = Number(rData[r][rScoreCol]) || 0;
      evaluated++;
      affectedStudents[toSafeString(rData[r][rUserCol]).trim().toLowerCase()] = true;

      if (Math.abs(nilaiBaru - nilaiLama) > 0.049) {
        updates.push({ row: r + 1, nilai: nilaiBaru });
        changed++;
      }
    }

    // ---------- 4. Tulis hanya baris yang nilainya berubah ----------
    for (var up = 0; up < updates.length; up++) {
      resultsSheet.getRange(updates[up].row, rScoreCol + 1).setValue(updates[up].nilai);
    }
    if (updates.length > 0) SpreadsheetApp.flush();

    var studentCount = Object.keys(affectedStudents).length;
    logActivity(username, 'Nilai Ulang', 'Ujian: ' + targetExamId + ' (' + examName + ') | ' + changed + ' nilai diperbarui dari ' + evaluated + ' hasil (' + studentCount + ' siswa)');

    var msg = changed > 0
      ? 'Nilai ulang selesai: ' + changed + ' hasil diperbarui dari ' + evaluated + ' hasil milik ' + studentCount + ' siswa.'
      : 'Nilai ulang selesai: tidak ada nilai yang berubah (' + evaluated + ' hasil diperiksa, ' + studentCount + ' siswa).';
    if (skipped > 0) {
      msg += ' ' + skipped + ' baris yang belum dikumpulkan dilewati.';
    }

    return {
      success: true,
      message: msg,
      exam_id: targetExamId,
      exam_name: examName,
      evaluated: evaluated,
      changed: changed,
      skipped: skipped,
      students: studentCount
    };
  } catch (err) {
    return { success: false, message: 'Gagal menilai ulang: ' + err.message };
  } finally {
    lock.releaseLock();
  }
}

// ==========================================
// 7. MODUL SISWA: UJIAN, AUTOSAVE & ANTI-CHEAT
// ==========================================

/**
 * Mengambil daftar ujian yang tersedia untuk Siswa
 * Hanya menampilkan ujian yang ditujukan untuk kelas siswa tersebut
 */
function getStudentAvailableExams(username, token) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }
  
  try {
    var ss = getSpreadsheet();
    var examsSheet = getSheetSafe(ss, SHEETS.EXAMS);
    var questionsSheet = getSheetSafe(ss, SHEETS.QUESTIONS);
    var resultsSheet = getSheetSafe(ss, SHEETS.RESULTS);
    var usersSheet = getSheetSafe(ss, SHEETS.USERS);
    
    // Cari kelas dan sesi siswa aktif
    var studentClass = '';
    var studentSession = '';
    if (usersSheet) {
      var uData = usersSheet.getDataRange().getValues();
      var uMap = getHeaderMap(usersSheet);
      var uCol = uMap['username'] !== undefined ? uMap['username'] : 1;
      var kCol = uMap['kelas'] !== undefined ? uMap['kelas'] : 5;
      var sCol = uMap['sesi'] !== undefined ? uMap['sesi'] : -1;
      for (var u = 1; u < uData.length; u++) {
        if (toSafeString(uData[u][uCol]).toLowerCase() === username.toLowerCase()) {
          studentClass = toSafeString(uData[u][kCol]).trim();
          if (sCol !== -1) {
            studentSession = toSafeString(uData[u][sCol]).trim();
          }
          break;
        }
      }
    }
    
    if (!examsSheet) {
      return { success: true, exams: [] };
    }
    
    var examsData = examsSheet.getDataRange().getValues();
    var questionsData = questionsSheet ? questionsSheet.getDataRange().getValues() : [];
    var resultsData = resultsSheet ? resultsSheet.getDataRange().getValues() : [];
    
    var map = getHeaderMap(examsSheet);
    var idCol = map['id_ujian'] !== undefined ? map['id_ujian'] : 0;
    var namaCol = map['nama_ujian'] !== undefined ? map['nama_ujian'] : 1;
    var durasiCol = map['durasi_menit'] !== undefined ? map['durasi_menit'] : 2;
    var mulaiCol = map['waktu_mulai'] !== undefined ? map['waktu_mulai'] : 3;
    var selesaiCol = map['waktu_selesai'] !== undefined ? map['waktu_selesai'] : 4;
    var acakSoalCol = map['acak_soal'] !== undefined ? map['acak_soal'] : 5;
    var acakOpsiCol = map['acak_opsi'] !== undefined ? map['acak_opsi'] : 6;
    var tokenCol = map['token_ujian'] !== undefined ? map['token_ujian'] : 7;
    var kelasCol = map['kelas'] !== undefined ? map['kelas'] : -1;
    var sesiCol = map['sesi'] !== undefined ? map['sesi'] : -1;
    var tipeCol = map['tipe_peserta'] !== undefined ? map['tipe_peserta'] : -1;
    var pesertaCol = map['peserta_khusus'] !== undefined ? map['peserta_khusus'] : -1;
    var tampilCol = map['jumlah_soal_tampil'] !== undefined ? map['jumlah_soal_tampil'] : -1;
    var showKeyCol = map['tampilkan_pembahasan'] !== undefined ? map['tampilkan_pembahasan'] : -1;
    var percobaanCol = map['jumlah_percobaan'] !== undefined ? map['jumlah_percobaan'] : -1;
    var maxViolCol = map['max_violations'] !== undefined ? map['max_violations'] : -1;
    var tindakanViolCol = map['tindakan_pelanggaran'] !== undefined ? map['tindakan_pelanggaran'] : -1;
    
    // Hitung jumlah soal per id_ujian
    var qCounts = {};
    for (var i = 1; i < questionsData.length; i++) {
      var eId = toSafeString(questionsData[i][1]);
      if (eId) {
        qCounts[eId] = (qCounts[eId] || 0) + 1;
      }
    }
    
    // Cek riwayat pengerjaan siswa (dikelompokkan per ujian, tiap baris = satu percobaan)
    var studentAttempts = {};
    var rMap = resultsSheet ? getHeaderMap(resultsSheet) : {};
    var rExamCol = rMap['id_ujian'] !== undefined ? rMap['id_ujian'] : 1;
    var rUserCol = rMap['username_siswa'] !== undefined ? rMap['username_siswa'] : 2;
    var rMulaiCol = rMap['waktu_mulai'] !== undefined ? rMap['waktu_mulai'] : 3;
    var rSubmitCol = rMap['waktu_submit'] !== undefined ? rMap['waktu_submit'] : 4;
    var rViolCol = rMap['jumlah_pelanggaran'] !== undefined ? rMap['jumlah_pelanggaran'] : 5;
    var rScoreCol = rMap['nilai_akhir'] !== undefined ? rMap['nilai_akhir'] : 6;
    var rStatusCol = rMap['status'] !== undefined ? rMap['status'] : 7;
    var rAttemptCol = rMap['percobaan_ke'] !== undefined ? rMap['percobaan_ke'] : -1;

    for (var j = 1; j < resultsData.length; j++) {
      var rUname = toSafeString(resultsData[j][rUserCol]);
      if (rUname.toLowerCase() !== username.toLowerCase()) continue;
      var rIdUjian = toSafeString(resultsData[j][rExamCol]);
      if (!rIdUjian) continue;
      if (!studentAttempts[rIdUjian]) studentAttempts[rIdUjian] = [];
      studentAttempts[rIdUjian].push({
        attempt: getResultAttemptNumber(resultsData[j], rAttemptCol),
        status: toSafeString(resultsData[j][rStatusCol]).trim().toUpperCase(),
        id_hasil: toSafeString(resultsData[j][0]),
        score: Number(resultsData[j][rScoreCol]) || 0,
        waktu_mulai: formatDateTimeIso(resultsData[j][rMulaiCol]),
        waktu_submit: formatDateTimeIso(resultsData[j][rSubmitCol]),
        jumlah_pelanggaran: Number(resultsData[j][rViolCol]) || 0
      });
    }
    
    var now = new Date();
    var activeList = [];
    var completedList = [];
    var allList = [];
    
    for (var k = 1; k < examsData.length; k++) {
      var examRow = examsData[k];
      var examId = toSafeString(examRow[idCol]);
      if (!examId) continue;
      
      var examClassesRaw = kelasCol !== -1 ? toSafeString(examRow[kelasCol]).trim() : '';
      var examSessionsRaw = sesiCol !== -1 ? toSafeString(examRow[sesiCol]).trim() : '';
      var tipePeserta = tipeCol !== -1 && toSafeString(examRow[tipeCol]) ? toSafeString(examRow[tipeCol]).trim().toLowerCase() : 'general';
      var pesertaKhususRaw = pesertaCol !== -1 ? toSafeString(examRow[pesertaCol]).trim() : '';

      if (tipePeserta === 'khusus') {
        // Filter target peserta khusus: Siswa harus terdaftar dalam daftar username peserta khusus
        var allowedStudents = pesertaKhususRaw.split(',').map(function(s) { return s.trim().toLowerCase(); }).filter(Boolean);
        var uLower = username.toLowerCase().trim();
        if (allowedStudents.indexOf(uLower) === -1) {
          // Ujian ini khusus untuk siswa tertentu dan akun siswa ini tidak terdaftar!
          continue;
        }
      } else {
        // Filter target kelas: Jika ujian menentukan kelas tertentu, pastikan kelas siswa cocok
        if (examClassesRaw && examClassesRaw.toLowerCase() !== 'semua' && examClassesRaw.toLowerCase() !== 'all' && examClassesRaw.toLowerCase() !== 'semua kelas' && examClassesRaw !== '-') {
          var allowedClasses = examClassesRaw.split(',').map(function(c) { return c.trim().toLowerCase(); });
          var sClassLower = studentClass.toLowerCase();
          var isMatch = false;
          for (var ac = 0; ac < allowedClasses.length; ac++) {
            if (allowedClasses[ac] && (allowedClasses[ac] === sClassLower || allowedClasses[ac] === 'semua' || allowedClasses[ac] === 'all' || allowedClasses[ac] === 'semua kelas')) {
              isMatch = true;
              break;
            }
          }
          if (!isMatch) {
            // Ujian ini bukan untuk kelas siswa ini, lewati!
            continue;
          }
        }

        // Filter target sesi: Jika ujian menentukan sesi tertentu, pastikan sesi siswa cocok
        if (examSessionsRaw && examSessionsRaw.toLowerCase() !== 'semua' && examSessionsRaw.toLowerCase() !== 'all' && examSessionsRaw.toLowerCase() !== 'semua sesi' && examSessionsRaw !== '-') {
          var allowedSessions = examSessionsRaw.split(',').map(function(s) { return s.trim().toLowerCase(); });
          var sSesiLower = studentSession.toLowerCase();
          var isSesiMatch = false;
          for (var as = 0; as < allowedSessions.length; as++) {
            if (allowedSessions[as] && (allowedSessions[as] === sSesiLower || allowedSessions[as] === 'semua' || allowedSessions[as] === 'all' || allowedSessions[as] === 'semua sesi')) {
              isSesiMatch = true;
              break;
            }
          }
          if (!isSesiMatch) {
            // Ujian ini bukan untuk sesi siswa ini, lewati!
            continue;
          }
        }
      }
      
      var qCount = qCounts[examId] || 0;
      if (qCount === 0) continue; // Jangan tampilkan jika belum ada soal
      
      var rawMulai = formatDateTimeIso(examRow[mulaiCol]);
      var rawSelesai = formatDateTimeIso(examRow[selesaiCol]);
      var startTime = rawMulai ? new Date(rawMulai) : null;
      var endTime = rawSelesai ? new Date(rawSelesai) : null;
      
      var attemptLimit = percobaanCol !== -1 ? normalizeExamAttempts(examRow[percobaanCol]) : 1;
      var attemptSummary = summarizeStudentAttempts(studentAttempts[examId] || [], attemptLimit);
      var latestAttempt = attemptSummary.rows.length > 0 ? attemptSummary.rows[attemptSummary.rows.length - 1] : null;

      // Ringkasan hasil siswa: status mengacu pada percobaan yang sedang/terakhir,
      // sedangkan nilai yang ditampilkan adalah NILAI TERTINGGI antar percobaan.
      var myResult = null;
      if (attemptSummary.draft) {
        myResult = {
          status: 'DRAFT',
          id_hasil: attemptSummary.draft.id_hasil,
          nilai_akhir: attemptSummary.best ? attemptSummary.best.score : 0,
          waktu_mulai: attemptSummary.draft.waktu_mulai,
          waktu_submit: attemptSummary.draft.waktu_submit,
          jumlah_pelanggaran: attemptSummary.draft.jumlah_pelanggaran
        };
      } else if (attemptSummary.is_blocked) {
        var blockedRow = attemptSummary.rows.filter(function(r) { return r.status === 'TERBLOKIR'; }).pop();
        myResult = {
          status: 'TERBLOKIR',
          id_hasil: blockedRow ? blockedRow.id_hasil : '',
          nilai_akhir: attemptSummary.best ? attemptSummary.best.score : 0,
          waktu_mulai: blockedRow ? blockedRow.waktu_mulai : '',
          waktu_submit: blockedRow ? blockedRow.waktu_submit : '',
          jumlah_pelanggaran: blockedRow ? blockedRow.jumlah_pelanggaran : 0
        };
      } else if (attemptSummary.best) {
        myResult = {
          status: 'SUBMITTED',
          id_hasil: attemptSummary.best.id_hasil,
          nilai_akhir: attemptSummary.best.score,
          waktu_mulai: attemptSummary.best.waktu_mulai,
          waktu_submit: attemptSummary.best.waktu_submit,
          jumlah_pelanggaran: attemptSummary.best.jumlah_pelanggaran
        };
      }

      if (myResult) {
        myResult.percobaan_ke = latestAttempt ? latestAttempt.attempt : 1;
        myResult.jumlah_percobaan_dilakukan = attemptSummary.attempts_used;
        myResult.nilai_tertinggi = attemptSummary.best ? attemptSummary.best.score : 0;

        // Total pelanggaran SELURUH percobaan (angka pada kartu riwayat siswa harus
        // sama dengan kolom pelanggaran pada rekap guru).
        var totalPelanggaranSemua = 0;
        attemptSummary.rows.forEach(function(rw) { totalPelanggaranSemua += Number(rw.jumlah_pelanggaran) || 0; });
        myResult.total_pelanggaran = totalPelanggaranSemua;
        if (myResult.status === 'SUBMITTED') {
          myResult.jumlah_pelanggaran = totalPelanggaranSemua;
        }
      }

      var examState = 'OPEN';
      if (attemptSummary.is_blocked) {
        examState = 'TERBLOKIR';
      } else if (attemptSummary.is_complete) {
        examState = 'SUBMITTED';
      } else if (startTime && !isNaN(startTime.getTime()) && now < startTime) {
        examState = 'UPCOMING';
      } else if (endTime && !isNaN(endTime.getTime()) && now > endTime) {
        examState = 'EXPIRED';
      }

      // Jika jendela ujian sudah tutup/belum dibuka tetapi siswa sudah punya hasil
      // submit, tampilkan sebagai ujian selesai (tidak ada kesempatan mengulang).
      if ((examState === 'EXPIRED' || examState === 'UPCOMING') && attemptSummary.best) {
        examState = 'SUBMITTED';
      }
      
      var tokenVal = toSafeString(examRow[tokenCol]);
      var item = {
        id_ujian: examId,
        nama_ujian: toSafeString(examRow[namaCol]),
        durasi_menit: Number(examRow[durasiCol]) || 60,
        waktu_mulai: rawMulai,
        waktu_selesai: rawSelesai,
        acak_soal: toSafeString(examRow[acakSoalCol]) || 'Ya',
        acak_opsi: toSafeString(examRow[acakOpsiCol]) || 'Ya',
        token_ujian: tokenVal,
        kelas: tipePeserta === 'khusus' ? 'Peserta Khusus' : (examClassesRaw || 'Semua Kelas'),
        sesi: examSessionsRaw || 'Semua Sesi',
        tipe_peserta: tipePeserta,
        peserta_khusus: pesertaKhususRaw,
        has_token: Boolean(tokenVal),
        jumlah_soal: qCount,
        jumlah_soal_tampil: tampilCol !== -1 ? (parseInt(examRow[tampilCol], 10) || 0) : 0,
        jumlah_percobaan: attemptLimit,
        percobaan_dilakukan: attemptSummary.attempts_used,
        sisa_percobaan: attemptSummary.attempts_left,
        nilai_tertinggi: attemptSummary.best ? attemptSummary.best.score : 0,
        tampilkan_pembahasan: showKeyCol !== -1 && toSafeString(examRow[showKeyCol]).trim().toLowerCase() === 'ya' ? 'Ya' : 'Tidak',
        max_violations: maxViolCol !== -1 ? normalizeExamMaxViolations(examRow[maxViolCol]) : 3,
        tindakan_pelanggaran: tindakanViolCol !== -1 ? normalizeViolationAction(examRow[tindakanViolCol]) : 'auto_submit',
        student_result: myResult,
        exam_state: examState
      };
      
      allList.push(item);

      // Riwayat & Nilai: ujian langsung masuk riwayat begitu ada minimal satu percobaan
      // yang sudah dikumpulkan (SUBMITTED). Siswa TIDAK wajib menghabiskan seluruh
      // kesempatan yang tersedia; sisa kesempatan tetap opsional.
      if (attemptSummary.best) {
        completedList.push(item);
      }

      if (examState === 'OPEN' || examState === 'TERBLOKIR') {
        // Masih bisa dikerjakan / dilanjutkan, termasuk sisa kesempatan untuk mengulang
        activeList.push(item);
      }
    }
    
    return {
      success: true,
      student_class: studentClass,
      student_session: studentSession,
      exams: activeList,
      completed_exams: completedList,
      all_exams: allList
    };
  } catch (err) {
    return { success: false, message: 'Gagal memuat ujian siswa: ' + err.message };
  }
}

/**
 * PEMBAHASAN UJIAN UNTUK SISWA
 * Mengembalikan kunci jawaban & pembahasan setiap butir soal HANYA jika:
 *  - sesi login valid,
 *  - siswa sudah menyelesaikan (men-submit) ujian tersebut,
 *  - opsi ujian `tampilkan_pembahasan` bernilai 'Ya'.
 * @param {string} username
 * @param {string} token
 * @param {string} id_ujian
 * @returns {object} { success, exam, questions:[{..., jawaban_siswa, is_benar}], summary }
 */
function getStudentExamReview(username, token, id_ujian) {
  var t0 = Date.now();
  console.log('[getStudentExamReview] mulai: user=' + username + ' ujian=' + id_ujian);
  if (!validateSession(username, token)) {
    console.log('[getStudentExamReview] sesi tidak valid (' + (Date.now() - t0) + 'ms)');
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }

  var targetExamId = toSafeString(id_ujian).trim();
  if (!targetExamId) {
    return { success: false, message: 'ID ujian tidak valid.' };
  }

  try {
    var ss = getSpreadsheet();
    var examsSheet = getSheetSafe(ss, SHEETS.EXAMS);
    var questionsSheet = getSheetSafe(ss, SHEETS.QUESTIONS);
    var resultsSheet = getSheetSafe(ss, SHEETS.RESULTS);

    if (!examsSheet || !questionsSheet || !resultsSheet) {
      return { success: false, message: 'Data ujian belum tersedia.' };
    }

    // 1. Baca data ujian & pastikan pembahasan diizinkan
    var eData = examsSheet.getDataRange().getValues();
    var eMap = getHeaderMap(examsSheet);
    var eIdCol = eMap['id_ujian'] !== undefined ? eMap['id_ujian'] : 0;
    var eNamaCol = eMap['nama_ujian'] !== undefined ? eMap['nama_ujian'] : 1;
    var eShowCol = eMap['tampilkan_pembahasan'] !== undefined ? eMap['tampilkan_pembahasan'] : -1;

    var examInfo = null;
    for (var e = 1; e < eData.length; e++) {
      if (toSafeString(eData[e][eIdCol]).trim() === targetExamId) {
        examInfo = {
          id_ujian: targetExamId,
          nama_ujian: toSafeString(eData[e][eNamaCol]),
          tampilkan_pembahasan: eShowCol !== -1 && toSafeString(eData[e][eShowCol]).trim().toLowerCase() === 'ya' ? 'Ya' : 'Tidak'
        };
        break;
      }
    }

    if (!examInfo) {
      return { success: false, message: 'Ujian tidak ditemukan.' };
    }

    if (examInfo.tampilkan_pembahasan !== 'Ya') {
      return { success: false, message: 'Kunci jawaban & pembahasan untuk ujian ini tidak dibuka oleh guru pengampu.' };
    }

    // 2. Pastikan siswa sudah menyelesaikan ujian
    var rData = resultsSheet.getDataRange().getValues();
    var rMap = getHeaderMap(resultsSheet);
    var rIdUjianCol = rMap['id_ujian'] !== undefined ? rMap['id_ujian'] : 1;
    var rUserCol = rMap['username_siswa'] !== undefined ? rMap['username_siswa'] : 2;
    var rStatusCol = rMap['status'] !== undefined ? rMap['status'] : 7;
    var rDetailCol = rMap['detail_jawaban'] !== undefined ? rMap['detail_jawaban'] : 8;
    var rTerpilihCol = rMap['soal_terpilih'] !== undefined ? rMap['soal_terpilih'] : 9;
    var rReviewScoreCol = rMap['nilai_akhir'] !== undefined ? rMap['nilai_akhir'] : 6;
    var rReviewAttemptCol = rMap['percobaan_ke'] !== undefined ? rMap['percobaan_ke'] : -1;

    var myResult = null;
    var uLower = toSafeString(username).trim().toLowerCase();
    for (var r = 1; r < rData.length; r++) {
      var rowExam = toSafeString(rData[r][rIdUjianCol]).trim();
      var rowUser = toSafeString(rData[r][rUserCol]).trim().toLowerCase();
      if (rowExam === targetExamId && rowUser === uLower) {
        var rowStatus = toSafeString(rData[r][rStatusCol]).trim().toUpperCase();
        var rowScore = Number(rData[r][rReviewScoreCol]) || 0;
        var rowAttempt = getResultAttemptNumber(rData[r], rReviewAttemptCol);
        // Pembahasan mengikuti PERCOBAAN DENGAN NILAI TERTINGGI (bukan percobaan terakhir)
        if (!myResult) {
          myResult = {
            status: rowStatus,
            score: rowScore,
            percobaan_ke: rowAttempt,
            detail: toSafeString(rData[r][rDetailCol]),
            terpilih: toSafeString(rData[r][rTerpilihCol])
          };
        } else if (rowStatus === 'SUBMITTED') {
          if (myResult.status !== 'SUBMITTED' || rowScore > Number(myResult.score || 0) ||
              (rowScore === Number(myResult.score || 0) && rowAttempt > Number(myResult.percobaan_ke || 1))) {
            myResult = {
              status: rowStatus,
              score: rowScore,
              percobaan_ke: rowAttempt,
              detail: toSafeString(rData[r][rDetailCol]),
              terpilih: toSafeString(rData[r][rTerpilihCol])
            };
          }
        }
      }
    }

    if (!myResult) {
      return { success: false, message: 'Anda belum menyelesaikan ujian ini.' };
    }
    if (myResult.status !== 'SUBMITTED') {
      return { success: false, message: 'Pembahasan hanya dapat dibuka setelah ujian Anda selesai dikumpulkan.' };
    }

    // Jawaban siswa tersimpan sebagai JSON { id_soal: 'A' }
    var studentAnswers = {};
    if (myResult.detail) {
      try {
        var parsed = JSON.parse(myResult.detail);
        if (parsed && typeof parsed === 'object') studentAnswers = parsed;
      } catch (errParse) {
        studentAnswers = {};
      }
    }

    // Paket soal acak milik siswa (jika ujian memakai "jumlah soal tampil")
    var selectedSet = null;
    if (myResult.terpilih) {
      selectedSet = {};
      myResult.terpilih.split(',').forEach(function(sid) {
        var clean = String(sid).trim();
        if (clean) selectedSet[clean] = true;
      });
      if (Object.keys(selectedSet).length === 0) selectedSet = null;
    }

    // 3. Susun daftar soal beserta kunci & pembahasan
    var qData = questionsSheet.getDataRange().getDisplayValues();
    var qMap = getHeaderMap(questionsSheet);
    var qIdUjianCol = qMap['id_ujian'] !== undefined ? qMap['id_ujian'] : 1;
    var qIdSoalCol = qMap['id_soal'] !== undefined ? qMap['id_soal'] : 0;
    var qTeksCol = qMap['teks_soal'] !== undefined ? qMap['teks_soal'] : 2;
    var qUrlCol = qMap['url_gambar'] !== undefined ? qMap['url_gambar'] : -1;
    var qKunciCol = qMap['kunci_jawaban'] !== undefined ? qMap['kunci_jawaban'] : 8;
    var qBobotCol = qMap['bobot'] !== undefined ? qMap['bobot'] : 9;
    var qIdTeksCol = qMap['id_teks'] !== undefined ? qMap['id_teks'] : -1;
    var qPembahasanCol = qMap['pembahasan'] !== undefined ? qMap['pembahasan'] : -1;
    var letters = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];

    var textMap = getExamTextMap(ss, targetExamId);
    var questions = [];
    var benar = 0;
    var salah = 0;
    var kosong = 0;

    for (var q = 1; q < qData.length; q++) {
      if (toSafeString(qData[q][qIdUjianCol]).trim() !== targetExamId) continue;
      var qId = toSafeString(qData[q][qIdSoalCol]);
      if (selectedSet && !selectedSet[qId]) continue;

      var kunci = toSafeString(qData[q][qKunciCol]).trim().toUpperCase();
      var jawabanSiswa = toSafeString(studentAnswers[qId]).trim().toUpperCase();
      var isBenar = Boolean(jawabanSiswa) && jawabanSiswa === kunci;
      if (!jawabanSiswa) kosong++;
      else if (isBenar) benar++;
      else salah++;

      var qIdTeks = qIdTeksCol !== -1 ? toSafeString(qData[q][qIdTeksCol]).trim() : '';
      var qTeksObj = textMap[qIdTeks] || null;

      var item = {
        id_soal: qId,
        teks_soal: toSafeString(qData[q][qTeksCol]),
        url_gambar: qUrlCol !== -1 ? toSafeString(qData[q][qUrlCol]) : '',
        kunci_jawaban: kunci,
        pembahasan: qPembahasanCol !== -1 ? toSafeString(qData[q][qPembahasanCol]) : '',
        bobot: Number(qData[q][qBobotCol]) || 1,
        jawaban_siswa: jawabanSiswa,
        is_benar: isBenar,
        nama_teks: qTeksObj ? qTeksObj.nama_teks : '',
        teks_konteks: qTeksObj ? qTeksObj.isi_teks : ''
      };

      letters.forEach(function(l) {
        var key = 'opsi_' + l;
        item[key] = qMap[key] !== undefined ? toSafeString(qData[q][qMap[key]]) : '';
      });

      questions.push(item);
    }

    if (questions.length === 0) {
      // Fallback: jika paket acak tidak tersimpan, tampilkan seluruh soal ujian
      for (var q2 = 1; q2 < qData.length; q2++) {
        if (toSafeString(qData[q2][qIdUjianCol]).trim() !== targetExamId) continue;
        var qId2 = toSafeString(qData[q2][qIdSoalCol]);
        var kunci2 = toSafeString(qData[q2][qKunciCol]).trim().toUpperCase();
        var jawaban2 = toSafeString(studentAnswers[qId2]).trim().toUpperCase();
        var isBenar2 = Boolean(jawaban2) && jawaban2 === kunci2;
        if (!jawaban2) kosong++;
        else if (isBenar2) benar++;
        else salah++;

        var qIdTeks2 = qIdTeksCol !== -1 ? toSafeString(qData[q2][qIdTeksCol]).trim() : '';
        var qTeksObj2 = textMap[qIdTeks2] || null;

        var item2 = {
          id_soal: qId2,
          teks_soal: toSafeString(qData[q2][qTeksCol]),
          url_gambar: qUrlCol !== -1 ? toSafeString(qData[q2][qUrlCol]) : '',
          kunci_jawaban: kunci2,
          pembahasan: qPembahasanCol !== -1 ? toSafeString(qData[q2][qPembahasanCol]) : '',
          bobot: Number(qData[q2][qBobotCol]) || 1,
          jawaban_siswa: jawaban2,
          is_benar: isBenar2,
          nama_teks: qTeksObj2 ? qTeksObj2.nama_teks : '',
          teks_konteks: qTeksObj2 ? qTeksObj2.isi_teks : ''
        };

        letters.forEach(function(l) {
          var key2 = 'opsi_' + l;
          item2[key2] = qMap[key2] !== undefined ? toSafeString(qData[q2][qMap[key2]]) : '';
        });

        questions.push(item2);
      }
    }

    console.log('[getStudentExamReview] selesai: ' + questions.length + ' soal dalam ' + (Date.now() - t0) + 'ms');
    return {
      success: true,
      exam: examInfo,
      questions: questions,
      summary: {
        total: questions.length,
        benar: benar,
        salah: salah,
        kosong: kosong
      }
    };
  } catch (err) {
    console.log('[getStudentExamReview] error: ' + err.message);
    return { success: false, message: 'Gagal memuat pembahasan ujian: ' + err.message };
  }
}

/**
 * Siswa Memulai / Melanjutkan Ujian
 * KEAMANAN TINGGI: Kunci jawaban DIHAPUS sebelum dikirim ke frontend!
 * @param {string} username - Username siswa
 * @param {string} token - Token sesi login siswa
 * @param {string} id_ujian - ID paket ujian
 * @param {string} inputToken - Token ujian yang dimasukkan siswa (jika diproteksi)
 */
function startStudentExam(username, token, id_ujian, inputToken) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Silakan login kembali.' };
  }
  
  var lock = LockService.getScriptLock();
  lock.tryLock(15000);
  try {
    var ss = getSpreadsheet();
    var examsSheet = getSheetSafe(ss, SHEETS.EXAMS);
    var questionsSheet = getSheetSafe(ss, SHEETS.QUESTIONS);
    var resultsSheet = getSheetSafe(ss, SHEETS.RESULTS);
    
    if (!examsSheet || !questionsSheet || !resultsSheet) {
      return { success: false, message: 'Database ujian belum siap.' };
    }

    // Pastikan kolom Results.percobaan_ke tersedia (migrasi ringan bila CreateDb belum dijalankan)
    var startResultMap = getHeaderMap(resultsSheet);
    if (startResultMap['percobaan_ke'] === undefined) {
      CreateDb();
      resultsSheet = getSheetSafe(ss, SHEETS.RESULTS) || resultsSheet;
      startResultMap = getHeaderMap(resultsSheet);
    }
    var startAttemptCol = startResultMap['percobaan_ke'] !== undefined ? startResultMap['percobaan_ke'] : -1;
    var startMulaiIdx = startResultMap['waktu_mulai'] !== undefined ? startResultMap['waktu_mulai'] : 3;
    var startViolIdx = startResultMap['jumlah_pelanggaran'] !== undefined ? startResultMap['jumlah_pelanggaran'] : 5;
    var startDetailIdx = startResultMap['detail_jawaban'] !== undefined ? startResultMap['detail_jawaban'] : 8;
    
    // 1. Ambil Info Ujian
    var examsData = examsSheet.getDataRange().getValues();
    var eMap = getHeaderMap(examsSheet);
    var exam = null;
    
    for (var i = 1; i < examsData.length; i++) {
      if (toSafeString(examsData[i][eMap['id_ujian'] || 0]) === id_ujian) {
        var eKelasCol = eMap['kelas'] !== undefined ? eMap['kelas'] : -1;
        var eSesiCol = eMap['sesi'] !== undefined ? eMap['sesi'] : -1;
        var eTipeCol = eMap['tipe_peserta'] !== undefined ? eMap['tipe_peserta'] : -1;
        var ePesertaCol = eMap['peserta_khusus'] !== undefined ? eMap['peserta_khusus'] : -1;
        var eTampilCol = eMap['jumlah_soal_tampil'] !== undefined ? eMap['jumlah_soal_tampil'] : -1;
        var ePercobaanCol = eMap['jumlah_percobaan'] !== undefined ? eMap['jumlah_percobaan'] : -1;
        var eMaxViolCol = eMap['max_violations'] !== undefined ? eMap['max_violations'] : -1;
        var eTindakanViolCol = eMap['tindakan_pelanggaran'] !== undefined ? eMap['tindakan_pelanggaran'] : -1;
        exam = {
          id_ujian: toSafeString(examsData[i][eMap['id_ujian'] || 0]),
          nama_ujian: toSafeString(examsData[i][eMap['nama_ujian'] || 1]),
          durasi_menit: Number(examsData[i][eMap['durasi_menit'] || 2]) || 60,
          waktu_mulai: formatDateTimeIso(examsData[i][eMap['waktu_mulai'] || 3]),
          waktu_selesai: formatDateTimeIso(examsData[i][eMap['waktu_selesai'] || 4]),
          acak_soal: toSafeString(examsData[i][eMap['acak_soal'] || 5]) || 'Ya',
          acak_opsi: toSafeString(examsData[i][eMap['acak_opsi'] !== undefined ? eMap['acak_opsi'] : 6]) || 'Ya',
          token_ujian: toSafeString(examsData[i][eMap['token_ujian'] !== undefined ? eMap['token_ujian'] : 7]).toUpperCase(),
          kelas: eKelasCol !== -1 ? toSafeString(examsData[i][eKelasCol]) : '',
          sesi: eSesiCol !== -1 ? toSafeString(examsData[i][eSesiCol]) : '',
          tipe_peserta: eTipeCol !== -1 && toSafeString(examsData[i][eTipeCol]) ? toSafeString(examsData[i][eTipeCol]).trim().toLowerCase() : 'general',
          peserta_khusus: ePesertaCol !== -1 ? toSafeString(examsData[i][ePesertaCol]) : '',
          jumlah_soal_tampil: eTampilCol !== -1 ? (parseInt(examsData[i][eTampilCol], 10) || 0) : 0,
          jumlah_percobaan: ePercobaanCol !== -1 ? normalizeExamAttempts(examsData[i][ePercobaanCol]) : 1,
          max_violations: eMaxViolCol !== -1 ? normalizeExamMaxViolations(examsData[i][eMaxViolCol]) : 3,
          tindakan_pelanggaran: eTindakanViolCol !== -1 ? normalizeViolationAction(examsData[i][eTindakanViolCol]) : 'auto_submit'
        };
        break;
      }
    }
    
    if (!exam) {
      return { success: false, message: 'Ujian tidak ditemukan.' };
    }
    
    // Cari data kelas dan sesi siswa aktif
    var studentClass = '';
    var studentSession = '';
    var usersSheet = getSheetSafe(ss, SHEETS.USERS);
    if (usersSheet) {
      var uData = usersSheet.getDataRange().getValues();
      var uMap = getHeaderMap(usersSheet);
      var uCol = uMap['username'] !== undefined ? uMap['username'] : 1;
      var kCol = uMap['kelas'] !== undefined ? uMap['kelas'] : 5;
      var sCol = uMap['sesi'] !== undefined ? uMap['sesi'] : -1;
      for (var u = 1; u < uData.length; u++) {
        if (toSafeString(uData[u][uCol]).toLowerCase() === username.toLowerCase()) {
          studentClass = toSafeString(uData[u][kCol]).trim();
          if (sCol !== -1) {
            studentSession = toSafeString(uData[u][sCol]).trim();
          }
          break;
        }
      }
    }

    // Validasi target peserta (Peserta Khusus vs General Kelas & Sesi)
    if (exam.tipe_peserta === 'khusus') {
      var allowedStudents = exam.peserta_khusus.split(',').map(function(s) { return s.trim().toLowerCase(); }).filter(Boolean);
      if (allowedStudents.indexOf(username.toLowerCase().trim()) === -1) {
        return { success: false, message: 'Ujian ini ditujukan khusus untuk siswa tertentu dan akun Anda tidak terdaftar sebagai peserta khusus.' };
      }
    } else {
      // Validasi target kelas peserta
      if (exam.kelas && exam.kelas.toLowerCase() !== 'semua' && exam.kelas.toLowerCase() !== 'all' && exam.kelas.toLowerCase() !== 'semua kelas' && exam.kelas !== '-') {
        var allowed = exam.kelas.split(',').map(function(c) { return c.trim().toLowerCase(); });
        var sClassLower = studentClass.toLowerCase();
        var isAllowed = false;
        for (var a = 0; a < allowed.length; a++) {
          if (allowed[a] && (allowed[a] === sClassLower || allowed[a] === 'semua' || allowed[a] === 'all' || allowed[a] === 'semua kelas')) {
            isAllowed = true;
            break;
          }
        }
        if (!isAllowed) {
          return { success: false, message: 'Ujian ini tidak dibuka untuk kelas Anda (' + (studentClass || '-') + '). Target kelas: ' + exam.kelas };
        }
      }

      // Validasi target sesi peserta
      if (exam.sesi && exam.sesi.toLowerCase() !== 'semua' && exam.sesi.toLowerCase() !== 'all' && exam.sesi.toLowerCase() !== 'semua sesi' && exam.sesi !== '-') {
        var allowedSesi = exam.sesi.split(',').map(function(s) { return s.trim().toLowerCase(); });
        var sSesiLower = studentSession.toLowerCase();
        var isSesiAllowed = false;
        for (var as = 0; as < allowedSesi.length; as++) {
          if (allowedSesi[as] && (allowedSesi[as] === sSesiLower || allowedSesi[as] === 'semua' || allowedSesi[as] === 'all' || allowedSesi[as] === 'semua sesi')) {
            isSesiAllowed = true;
            break;
          }
        }
        if (!isSesiAllowed) {
          return { success: false, message: 'Ujian ini tidak dibuka untuk sesi Anda (' + (studentSession || '-') + '). Target sesi: ' + exam.sesi };
        }
      }
    }
    
    // 2. Cek riwayat pengerjaan di Results (mendukung beberapa percobaan / kesempatan)
    var maxAttempts = normalizeExamAttempts(exam.jumlah_percobaan);
    var existingRecord = findAndConsolidateStudentResult(resultsSheet, id_ujian, username);
    var draftData = null;
    var isResume = false;
    var attemptNumber = 1;
    
    if (existingRecord) {
      var attemptsUsed = existingRecord.total_percobaan || 1;
      var bestScore = Number(existingRecord.nilai_tertinggi) || 0;
      attemptNumber = existingRecord.percobaan_ke || attemptsUsed;

      if (existingRecord.status === 'TERBLOKIR') {
        return { success: false, message: 'Akses Anda ke ujian ini terblokir karena batas pelanggaran keamanan tercapai. Silakan hubungi guru/pengawas untuk membuka blokir.' };
      }

      if (existingRecord.status === 'SUBMITTED') {
        if (attemptsUsed >= maxAttempts) {
          var limitMessage = maxAttempts > 1
            ? 'Anda sudah menggunakan seluruh ' + maxAttempts + ' kesempatan mengerjakan ujian ini. Nilai tertinggi Anda: ' + bestScore + '.'
            : 'Anda sudah mengumpulkan ujian ini.';
          return { success: false, message: limitMessage };
        }
        // Masih ada kesempatan tersisa → mulai percobaan baru
        attemptNumber = attemptsUsed + 1;
        isResume = false;
      } else {
        isResume = true;
        draftData = {
          id_hasil: toSafeString(existingRecord.data[0]),
          waktu_mulai: formatDateTimeIso(existingRecord.data[startMulaiIdx]),
          jumlah_pelanggaran: Number(existingRecord.data[startViolIdx]) || 0,
          detail_jawaban: toSafeString(existingRecord.data[startDetailIdx]) || '{}',
          percobaan_ke: attemptNumber,
          is_resume: true
        };
      }
    }

    // Verifikasi Token Ujian jika ujian memiliki token
    // Catatan: Jika siswa melanjutkan ujian yang sedang berlangsung (isResume / status DRAFT), token tidak ditagih ulang.
    if (exam.token_ujian && !isResume) {
      var enteredToken = toSafeString(inputToken).trim().toUpperCase();
      if (!enteredToken || enteredToken !== exam.token_ujian) {
        return { success: false, message: 'Token ujian salah. Silakan minta token yang valid kepada guru pengawas.' };
      }
    }
    
    if (!draftData) {
      var nowStr = Utilities.formatDate(new Date(), Session.getScriptTimeZone() || 'Asia/Jakarta', 'yyyy-MM-dd HH:mm:ss');
      var newResultId = 'RES-' + Utilities.getUuid().substring(0, 8).toUpperCase();
      resultsSheet.appendRow([
        newResultId,
        id_ujian,
        username,
        nowStr,
        '',       // waktu_submit
        0,        // jumlah_pelanggaran
        0,        // nilai_akhir
        'DRAFT',  // status
        '{}'      // detail_jawaban
      ]);
      // Simpan nomor percobaan pada baris yang baru dibuat
      if (startAttemptCol !== -1) {
        try {
          resultsSheet.getRange(resultsSheet.getLastRow(), startAttemptCol + 1).setNumberFormat('@').setValue(String(attemptNumber));
        } catch (eAttempt) {
          Logger.log('Gagal menyimpan nomor percobaan: ' + eAttempt.toString());
        }
      }
      SpreadsheetApp.flush();
      draftData = {
        id_hasil: newResultId,
        waktu_mulai: nowStr,
        jumlah_pelanggaran: 0,
        detail_jawaban: '{}',
        percobaan_ke: attemptNumber,
        is_resume: false
      };
    }
    
    // 3. Ambil Soal-soal (Sembunyikan kunci jawaban!)
    var qData = questionsSheet.getDataRange().getDisplayValues();
    var qMap = getHeaderMap(questionsSheet);
    var questions = [];
    var letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
    var idUjianCol = qMap['id_ujian'] !== undefined ? qMap['id_ujian'] : 1;
    var idSoalCol = qMap['id_soal'] !== undefined ? qMap['id_soal'] : 0;
    var teksCol = qMap['teks_soal'] !== undefined ? qMap['teks_soal'] : 2;
    var urlCol = qMap['url_gambar'] !== undefined ? qMap['url_gambar'] : -1;
    var bobotCol = qMap['bobot'] !== undefined ? qMap['bobot'] : 9;
    var idTeksCol = qMap['id_teks'] !== undefined ? qMap['id_teks'] : -1;
    
    // Teks/Konteks/Stimulan yang ditampilkan di atas pertanyaan
    var textMap = getExamTextMap(ss, id_ujian);
    
    for (var k = 1; k < qData.length; k++) {
      if (toSafeString(qData[k][idUjianCol]) === id_ujian) {
        var qIdTeks = idTeksCol !== -1 ? toSafeString(qData[k][idTeksCol]).trim() : '';
        var qTeksObj = textMap[qIdTeks] || null;
        var qObj = {
          id_soal: toSafeString(qData[k][idSoalCol]),
          teks_soal: toSafeString(qData[k][teksCol]),
          url_gambar: urlCol !== -1 ? toSafeString(qData[k][urlCol]) : '',
          bobot: Number(qData[k][bobotCol]) || 1,
          id_teks: qIdTeks,
          nama_teks: qTeksObj ? qTeksObj.nama_teks : '',
          teks_konteks: qTeksObj ? qTeksObj.isi_teks : ''
        };
        letters.forEach(function(l) {
          var key = 'opsi_' + l.toLowerCase();
          qObj[key] = qMap[key] !== undefined ? toSafeString(qData[k][qMap[key]]) : '';
        });
        questions.push(qObj);
      }
    }
    
    if (questions.length === 0) {
      return { success: false, message: 'Ujian belum memiliki soal pilihan ganda.' };
    }

    // 3b. PENGAMBILAN SOAL ACAK SESUAI "JUMLAH SOAL TAMPIL"
    // Jika ujian mengisi jumlah_soal_tampil (misal 50 dari 100 butir), sistem memilih
    // soal secara ACAK untuk siswa ini. Pilihan disimpan di kolom Results.soal_terpilih
    // agar KONSISTEN saat resume, autosave, maupun submit (kolom itu juga acuan penilaian).
    var jumlahSoalTampil = Number(exam.jumlah_soal_tampil) || 0;

    var selMap = getHeaderMap(resultsSheet);
    // Pastikan kolom Results.soal_terpilih sudah ada (migrasi ringan bila CreateDb belum dijalankan)
    if (selMap['soal_terpilih'] === undefined) {
      CreateDb();
      resultsSheet = getSheetSafe(ss, SHEETS.RESULTS) || resultsSheet;
      selMap = getHeaderMap(resultsSheet);
    }
    var selColIdx = selMap['soal_terpilih'] !== undefined ? selMap['soal_terpilih'] : 9;

    // Baris Results yang menjadi acuan percobaan AKTIF (bisa baris baru atau baris resume)
    var resultRecord = findAndConsolidateStudentResult(resultsSheet, id_ujian, username, attemptNumber);
    var storedRaw = '';
    var resultRowNumber = -1;
    if (resultRecord) {
      resultRowNumber = resultRecord.rowNumber;
      if (resultRecord.data && resultRecord.data[selColIdx] !== undefined) {
        storedRaw = toSafeString(resultRecord.data[selColIdx]);
      }
    }

    var bankIds = [];
    var bankSet = {};
    questions.forEach(function(qb) {
      if (qb.id_soal && !bankSet[qb.id_soal]) {
        bankSet[qb.id_soal] = true;
        bankIds.push(qb.id_soal);
      }
    });

    // Ambil pilihan tersimpan yang masih valid (soalnya belum dihapus guru)
    var selectedIds = [];
    if (storedRaw) {
      storedRaw.split(',').forEach(function(qidRaw) {
        var qid = String(qidRaw).trim();
        if (qid && bankSet[qid] && selectedIds.indexOf(qid) === -1) {
          selectedIds.push(qid);
        }
      });
    }

    if (selectedIds.length > 0) {
      // Paket tersimpan menjadi acuan. Jika batas dinaikkan, lengkapi sisanya secara acak
      // (jika batas diturunkan, paket yang sedang dikerjakan tetap dipertahankan agar progres tidak hilang).
      var desiredCount = Math.min(jumlahSoalTampil > 0 ? jumlahSoalTampil : selectedIds.length, bankIds.length);
      if (selectedIds.length < desiredCount) {
        var chosenSet = {};
        selectedIds.forEach(function(qidSel) { chosenSet[qidSel] = true; });
        var extraIds = bankIds.filter(function(qidExtra) { return !chosenSet[qidExtra]; });
        for (var ei = extraIds.length - 1; ei > 0; ei--) {
          var ej = Math.floor(Math.random() * (ei + 1));
          var eTmp = extraIds[ei];
          extraIds[ei] = extraIds[ej];
          extraIds[ej] = eTmp;
        }
        selectedIds = selectedIds.concat(extraIds.slice(0, desiredCount - selectedIds.length));
      }
    } else if (jumlahSoalTampil > 0 && jumlahSoalTampil < questions.length) {
      // Belum ada paket tersimpan => acak seluruh bank soal lalu ambil N pertama (completely random)
      var poolIds = bankIds.slice();
      for (var pi = poolIds.length - 1; pi > 0; pi--) {
        var pj = Math.floor(Math.random() * (pi + 1));
        var pTmp = poolIds[pi];
        poolIds[pi] = poolIds[pj];
        poolIds[pj] = pTmp;
      }
      selectedIds = poolIds.slice(0, jumlahSoalTampil);
    }

    if (selectedIds.length > 0) {
      // Persist pilihan agar tidak berubah selama pengerjaan berlangsung
      if (resultRowNumber > 0) {
        try {
          resultsSheet.getRange(resultRowNumber, selColIdx + 1).setValue(selectedIds.join(','));
          SpreadsheetApp.flush();
        } catch (eSel) {
          Logger.log('Gagal menyimpan daftar soal terpilih: ' + eSel.toString());
        }
      }

      var chosenFinal = {};
      selectedIds.forEach(function(qidFinal) { chosenFinal[qidFinal] = true; });
      questions = questions.filter(function(qf) { return chosenFinal[qf.id_soal]; });
    }
    
    // Generator pseudo-random deterministik per siswa
    function createPRNG(seedStr) {
      var hash = 0;
      for (var p = 0; p < seedStr.length; p++) {
        hash = ((hash << 5) - hash) + seedStr.charCodeAt(p);
        hash |= 0;
      }
      var seed = Math.abs(hash) || 1234567;
      return function() {
        seed = (seed * 9301 + 49297) % 233280;
        return seed / 233280;
      };
    }
    
    var isAcakOpsi = exam.acak_opsi === 'Ya';
    var isAcakSoal = exam.acak_soal === 'Ya';
    var alphabet = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
    
    // Proses opsi jawaban untuk setiap butir soal
    for (var m = 0; m < questions.length; m++) {
      var qItem = questions[m];
      var rawOpts = [];
      alphabet.forEach(function(letter) {
        var key = 'opsi_' + letter.toLowerCase();
        var optVal = qItem[key] || '';
        if (optVal && optVal.trim().length > 0) {
          rawOpts.push({ origKey: letter, text: optVal });
        }
      });
      
      if (isAcakOpsi) {
        var optRng = createPRNG(username.toLowerCase() + '_' + qItem.id_soal);
        for (var s = rawOpts.length - 1; s > 0; s--) {
          var r = Math.floor(optRng() * (s + 1));
          var tempOpt = rawOpts[s];
          rawOpts[s] = rawOpts[r];
          rawOpts[r] = tempOpt;
        }
      }
      
      qItem.shuffled_options = rawOpts.map(function(o, idx) {
        return {
          displayLabel: alphabet[idx] || ('Opsi ' + (idx + 1)),
          origKey: o.origKey,
          text: o.text
        };
      });
    }
    
    // Pengacakan urutan soal deterministik per siswa
    if (isAcakSoal) {
      var qRng = createPRNG(username.toLowerCase() + '_' + id_ujian);
      for (var qIdx = questions.length - 1; qIdx > 0; qIdx--) {
        var randQ = Math.floor(qRng() * (qIdx + 1));
        var tempQ = questions[qIdx];
        questions[qIdx] = questions[randQ];
        questions[randQ] = tempQ;
      }
    }
    
    logActivity(username, 'Start Exam', 'Memulai ujian: ' + exam.nama_ujian + ' (' + questions.length + ' soal)' + ' | Percobaan ke-' + attemptNumber + '/' + maxAttempts);
    
    return {
      success: true,
      exam: exam,
      questions: questions,
      draft: draftData
    };
  } catch (e) {
    return { success: false, message: 'Gagal memuat lembar ujian: ' + e.message };
  } finally {
    lock.releaseLock();
  }
}

/**
 * FITUR PRD 3.1: LAYER 2 PERIODIC BACKGROUND DATABASE SYNC (AUTOSAVE)
 * Menyimpan jawaban draft siswa ke spreadsheet secara berkala.
 */
function autosaveExam(username, token, payload) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa.' };
  }
  
  var id_ujian = toSafeString(payload.id_ujian).trim();
  var answersJson = typeof payload.answers === 'string' ? payload.answers : JSON.stringify(payload.answers || {});
  var violations = Number(payload.violations) || 0;
  
  var lock = LockService.getScriptLock();
  // Gunakan timeout yang cukup untuk menangani concurrency
  if (!lock.tryLock(10000)) {
    return { success: false, message: 'Server sibuk saat autosave. Draf tetap aman di localStorage.' };
  }
  
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.RESULTS);
    if (!sheet) {
      return { success: false, message: 'Sheet Results tidak ditemukan.' };
    }

    // Pastikan kolom percobaan_ke tersedia (migrasi ringan bila CreateDb belum dijalankan)
    var saMap = getHeaderMap(sheet);
    if (saMap['percobaan_ke'] === undefined) {
      CreateDb();
      sheet = getSheetSafe(ss, SHEETS.RESULTS) || sheet;
      saMap = getHeaderMap(sheet);
    }
    var saAttemptCol = saMap['percobaan_ke'] !== undefined ? saMap['percobaan_ke'] : -1;
    var saViolCol = saMap['jumlah_pelanggaran'] !== undefined ? saMap['jumlah_pelanggaran'] : 5;
    var saDetailCol = saMap['detail_jawaban'] !== undefined ? saMap['detail_jawaban'] : 8;
    var saRequestedAttempt = payload && payload.percobaan_ke !== undefined ? payload.percobaan_ke : undefined;
    
    // Cari & konsolidasi agar tidak pernah terjadi data ganda
    var existingRecord = findAndConsolidateStudentResult(sheet, id_ujian, username, saRequestedAttempt);
    
    if (existingRecord) {
      // Jika status sudah SUBMITTED, jangan timpa jawaban
      if (existingRecord.status === 'SUBMITTED') {
        return { success: false, message: 'Ujian sudah disubmit sebelumnya.' };
      }
      // UPDATE in-place baris yang sudah ada, jangan append!
      sheet.getRange(existingRecord.rowNumber, saViolCol + 1).setValue(violations);
      sheet.getRange(existingRecord.rowNumber, saDetailCol + 1).setValue(answersJson);
    } else {
      // Jika baris belum ada sama sekali, baru append 1 baris draft
      var newId = 'RES-' + Utilities.getUuid().substring(0, 8).toUpperCase();
      var nowStr = Utilities.formatDate(new Date(), Session.getScriptTimeZone() || 'Asia/Jakarta', 'yyyy-MM-dd HH:mm:ss');
      sheet.appendRow([
        newId,
        id_ujian,
        username,
        nowStr,
        '',
        violations,
        0,
        'DRAFT',
        answersJson
      ]);
      if (saAttemptCol !== -1) {
        try {
          sheet.getRange(sheet.getLastRow(), saAttemptCol + 1).setNumberFormat('@').setValue(String(normalizeExamAttempts(saRequestedAttempt)));
        } catch (eAttempt) {}
      }
    }
    
    return {
      success: true,
      timestamp: Utilities.formatDate(new Date(), Session.getScriptTimeZone() || 'Asia/Jakarta', 'HH:mm:ss')
    };
  } catch (e) {
    return { success: false, message: e.message };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Mencatat log pelanggaran anti-cheat ke sheet Logs & Results
 */
function recordViolation(username, token, payload) {
  if (!validateSession(username, token)) {
    return { success: false };
  }
  
  var id_ujian = toSafeString(payload.id_ujian).trim();
  var jenis = payload.jenis || 'Tab Switch';
  var violations = Number(payload.violations) || 1;
  var keterangan = payload.keterangan || 'Terdeteksi meninggalkan layar ujian.';
  
  logActivity(username, jenis, 'Ujian: ' + id_ujian + ' | Pelanggaran ke-' + violations + ' | ' + keterangan);
  
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.RESULTS);
    if (sheet) {
      var existingRecord = findAndConsolidateStudentResult(sheet, id_ujian, username);
      if (existingRecord && existingRecord.status !== 'SUBMITTED') {
        sheet.getRange(existingRecord.rowNumber, 6).setValue(violations);
      }
    }
  } catch (e) {}
  
  return { success: true };
}

/**
 * FITUR FINALISASI: Submit Ujian (Manual atau Auto-Submit akibat Waktu Habis / Max Pelanggaran)
 * Menghitung nilai secara server-side dengan mencocokkan kunci jawaban.
 * Selalu meng-update in-place record siswa di sheet Results sehingga tidak menghasilkan data ganda.
 */
function submitExam(username, token, payload) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi kedaluwarsa. Gagal mengirim jawaban.' };
  }
  
  var id_ujian = toSafeString(payload.id_ujian).trim();
  var answers = payload.answers || {}; // { id_soal: 'A', id_soal2: 'B' }
  var violations = Number(payload.violations) || 0;
  var isAutoSubmit = Boolean(payload.isAutoSubmit);
  var reason = payload.reason || (isAutoSubmit ? 'Auto Submit Sistem' : 'Selesai Manual');
  
  var lock = LockService.getScriptLock();
  lock.tryLock(15000);
  try {
    var ss = getSpreadsheet();
    var questionsSheet = getSheetSafe(ss, SHEETS.QUESTIONS);
    var resultsSheet = getSheetSafe(ss, SHEETS.RESULTS);
    
    if (!resultsSheet) {
      return { success: false, message: 'Sheet Results tidak ditemukan.' };
    }

    // Pastikan kolom Results.percobaan_ke tersedia (migrasi ringan bila CreateDb belum dijalankan)
    var subRMap = getHeaderMap(resultsSheet);
    if (subRMap['percobaan_ke'] === undefined) {
      CreateDb();
      resultsSheet = getSheetSafe(ss, SHEETS.RESULTS) || resultsSheet;
      subRMap = getHeaderMap(resultsSheet);
    }
    var subAttemptCol = subRMap['percobaan_ke'] !== undefined ? subRMap['percobaan_ke'] : -1;
    var subMulaiIdx = subRMap['waktu_mulai'] !== undefined ? subRMap['waktu_mulai'] : 3;
    var subSubmitIdx = subRMap['waktu_submit'] !== undefined ? subRMap['waktu_submit'] : 4;
    var subViolIdx = subRMap['jumlah_pelanggaran'] !== undefined ? subRMap['jumlah_pelanggaran'] : 5;
    var subScoreIdx = subRMap['nilai_akhir'] !== undefined ? subRMap['nilai_akhir'] : 6;
    var subStatusIdx = subRMap['status'] !== undefined ? subRMap['status'] : 7;
    var subDetailIdx = subRMap['detail_jawaban'] !== undefined ? subRMap['detail_jawaban'] : 8;
    var subSelIdx = subRMap['soal_terpilih'] !== undefined ? subRMap['soal_terpilih'] : 9;

    var requestedAttempt = payload.percobaan_ke !== undefined ? payload.percobaan_ke : undefined;
    
    // Ambil baris Results milik siswa SEBELUM penilaian (untuk membaca paket soal terpilih)
    var existingRecord = findAndConsolidateStudentResult(resultsSheet, id_ujian, username, requestedAttempt);

    // Cegah pengumpulan ganda yang dapat menimpa nilai percobaan yang sudah selesai
    if (existingRecord && existingRecord.status === 'SUBMITTED') {
      return { success: false, message: 'Ujian ini sudah Anda kumpulkan sebelumnya.' };
    }

    var submitAttemptNumber = existingRecord ? (existingRecord.percobaan_ke || 1) : normalizeExamAttempts(requestedAttempt);
    
    // Paket soal acak milik siswa (kolom Results.soal_terpilih).
    // Jika kosong => ujian tanpa batas "jumlah soal tampil" (nilai seluruh soal).
    var selectedSoalSet = null;
    if (existingRecord && existingRecord.data) {
      var subSelRaw = existingRecord.data[subSelIdx] !== undefined ? toSafeString(existingRecord.data[subSelIdx]) : '';
      if (subSelRaw) {
        selectedSoalSet = {};
        subSelRaw.split(',').forEach(function(sidRaw) {
          var sid = String(sidRaw).trim();
          if (sid) selectedSoalSet[sid] = true;
        });
        if (Object.keys(selectedSoalSet).length === 0) selectedSoalSet = null;
      }
    }
    
    // 1. Ambil data kunci jawaban dari Questions
    var qData = questionsSheet ? questionsSheet.getDataRange().getDisplayValues() : [];
    var qMap = questionsSheet ? getHeaderMap(questionsSheet) : {};
    var totalBobot = 0;
    var skorDiperoleh = 0;
    var correctCount = 0;
    var totalQuestions = 0;
    
    var subIdUjianCol = qMap['id_ujian'] !== undefined ? qMap['id_ujian'] : 1;
    var subIdSoalCol = qMap['id_soal'] !== undefined ? qMap['id_soal'] : 0;
    var subKunciCol = qMap['kunci_jawaban'] !== undefined ? qMap['kunci_jawaban'] : 8;
    var subBobotCol = qMap['bobot'] !== undefined ? qMap['bobot'] : 9;
    
    var unansweredCount = 0;
    
    for (var i = 1; i < qData.length; i++) {
      if (toSafeString(qData[i][subIdUjianCol]).trim() === id_ujian) {
        var qId = toSafeString(qData[i][subIdSoalCol]);
        // Hanya nilai soal yang memang ditampilkan ke siswa ini (paket acak per siswa)
        if (selectedSoalSet && !selectedSoalSet[qId]) {
          continue;
        }
        totalQuestions++;
        var kunci = toSafeString(qData[i][subKunciCol]).trim().toUpperCase();
        var bobot = Number(qData[i][subBobotCol]) || 1;
        totalBobot += bobot;
        
        var studentAns = toSafeString(answers[qId]).trim().toUpperCase();
        if (!studentAns) {
          unansweredCount++;
        } else if (studentAns === kunci) {
          skorDiperoleh += bobot;
          correctCount++;
        }
      }
    }
    
    if (!isAutoSubmit && unansweredCount > 0) {
      return {
        success: false,
        message: 'Jawaban tidak dapat dikirim karena masih ada ' + unansweredCount + ' butir soal yang belum dijawab. Harap lengkapi seluruh soal sebelum mengumpulkan ujian.'
      };
    }
    
    // Normalisasi nilai skala 0 - 100
    var nilaiAkhir = totalBobot > 0 ? Math.round((skorDiperoleh / totalBobot) * 100 * 10) / 10 : 0;
    
    // 2. Update status pengerjaan di Results (UPDATE in-place baris yang sudah ada, cegah data ganda!)
    //    existingRecord sudah diambil sejak awal (lihat di atas).
    var nowStr = Utilities.formatDate(new Date(), Session.getScriptTimeZone() || 'Asia/Jakarta', 'yyyy-MM-dd HH:mm:ss');
    var answersJsonStr = JSON.stringify(answers);
    
    if (existingRecord) {
      // UPDATE in-place hanya pada baris percobaan yang sedang dikerjakan
      resultsSheet.getRange(existingRecord.rowNumber, subSubmitIdx + 1).setValue(nowStr);
      resultsSheet.getRange(existingRecord.rowNumber, subViolIdx + 1).setValue(violations);
      resultsSheet.getRange(existingRecord.rowNumber, subScoreIdx + 1).setValue(nilaiAkhir);
      resultsSheet.getRange(existingRecord.rowNumber, subStatusIdx + 1).setValue('SUBMITTED');
      resultsSheet.getRange(existingRecord.rowNumber, subDetailIdx + 1).setValue(answersJsonStr);
    } else {
      var newId = 'RES-' + Utilities.getUuid().substring(0, 8).toUpperCase();
      resultsSheet.appendRow([
        newId,
        id_ujian,
        username,
        nowStr,          // waktu_mulai
        nowStr,          // waktu_submit
        violations,
        nilaiAkhir,
        'SUBMITTED',
        answersJsonStr
      ]);
      if (subAttemptCol !== -1) {
        try {
          resultsSheet.getRange(resultsSheet.getLastRow(), subAttemptCol + 1).setNumberFormat('@').setValue(String(submitAttemptNumber));
        } catch (eAttempt) {}
      }
    }
    SpreadsheetApp.flush();

    // Nilai tertinggi antar percobaan + sisa kesempatan untuk pesan ke siswa
    var submitSummary = findAndConsolidateStudentResult(resultsSheet, id_ujian, username, submitAttemptNumber);
    var nilaiTertinggi = submitSummary ? Number(submitSummary.nilai_tertinggi) || nilaiAkhir : nilaiAkhir;
    var attemptsLimit = 1;
    var examsSheetSubmit = getSheetSafe(ss, SHEETS.EXAMS);
    if (examsSheetSubmit) {
      var esMap = getHeaderMap(examsSheetSubmit);
      var esIdCol = esMap['id_ujian'] !== undefined ? esMap['id_ujian'] : 0;
      var esPercobaanCol = esMap['jumlah_percobaan'] !== undefined ? esMap['jumlah_percobaan'] : -1;
      var esData = examsSheetSubmit.getDataRange().getValues();
      for (var es = 1; es < esData.length; es++) {
        if (toSafeString(esData[es][esIdCol]).trim() === id_ujian) {
          attemptsLimit = esPercobaanCol !== -1 ? normalizeExamAttempts(esData[es][esPercobaanCol]) : 1;
          break;
        }
      }
    }
    var attemptsUsed = submitSummary ? submitSummary.total_percobaan : submitAttemptNumber;
    var sisaPercobaan = Math.max(0, attemptsLimit - attemptsUsed);
    
    logActivity(
      username,
      isAutoSubmit ? 'Auto Submit' : 'Submit Ujian',
      'Ujian: ' + id_ujian + ' | Percobaan: ' + submitAttemptNumber + '/' + attemptsLimit + ' | Nilai: ' + nilaiAkhir + ' | Nilai Tertinggi: ' + nilaiTertinggi + ' | Benar: ' + correctCount + '/' + totalQuestions + ' | Alasan: ' + reason
    );
    
    return {
      success: true,
      score: nilaiAkhir,
      nilai_tertinggi: nilaiTertinggi,
      percobaan_ke: submitAttemptNumber,
      jumlah_percobaan: attemptsLimit,
      sisa_percobaan: sisaPercobaan,
      correctCount: correctCount,
      totalQuestions: totalQuestions,
      message: isAutoSubmit ? 'Ujian telah di-submit otomatis karena ' + reason : 'Ujian berhasil diselesaikan dan dikirim.'
    };
  } catch (e) {
    return { success: false, message: 'Gagal memproses submit ujian: ' + e.message };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Memblokir siswa dari ujian akibat mencapai batas maksimal pelanggaran anti-curang
 */
function blockStudentExam(username, token, payload) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid. Silakan login kembali.' };
  }
  
  var id_ujian = toSafeString(payload.id_ujian).trim();
  var violations = Number(payload.violations) || 1;
  var reason = payload.reason || 'Batas maksimal pelanggaran keamanan tercapai';
  var answersJson = typeof payload.answers === 'string' ? payload.answers : JSON.stringify(payload.answers || {});

  logActivity(username, 'Auto Blokir Pelanggaran', 'Ujian: ' + id_ujian + ' | Pelanggaran ke-' + violations + ' | ' + reason);

  var lock = LockService.getScriptLock();
  if (!lock.tryLock(10000)) {
    return { success: false, message: 'Server sibuk saat memblokir ujian.' };
  }
  
  try {
    var ss = getSpreadsheet();
    var sheet = getSheetSafe(ss, SHEETS.RESULTS);
    if (!sheet) {
      return { success: false, message: 'Sheet Results tidak ditemukan.' };
    }
    
    var existingRecord = findAndConsolidateStudentResult(sheet, id_ujian, username, payload && payload.percobaan_ke !== undefined ? payload.percobaan_ke : undefined);

    var blkMap = getHeaderMap(sheet);
    var blkViolCol = blkMap['jumlah_pelanggaran'] !== undefined ? blkMap['jumlah_pelanggaran'] : 5;
    var blkStatusCol = blkMap['status'] !== undefined ? blkMap['status'] : 7;
    var blkDetailCol = blkMap['detail_jawaban'] !== undefined ? blkMap['detail_jawaban'] : 8;
    var blkAttemptCol = blkMap['percobaan_ke'] !== undefined ? blkMap['percobaan_ke'] : -1;

    if (existingRecord) {
      if (existingRecord.status === 'SUBMITTED') {
        return { success: false, message: 'Ujian sudah disubmit sebelumnya.' };
      }
      sheet.getRange(existingRecord.rowNumber, blkViolCol + 1).setValue(violations);
      sheet.getRange(existingRecord.rowNumber, blkStatusCol + 1).setValue('TERBLOKIR');
      if (answersJson && answersJson !== '{}') {
        sheet.getRange(existingRecord.rowNumber, blkDetailCol + 1).setValue(answersJson);
      }
    } else {
      var newId = 'RES-' + Utilities.getUuid().substring(0, 8).toUpperCase();
      var nowStr = Utilities.formatDate(new Date(), Session.getScriptTimeZone() || 'Asia/Jakarta', 'yyyy-MM-dd HH:mm:ss');
      sheet.appendRow([
        newId,
        id_ujian,
        username,
        nowStr,
        '',
        violations,
        0,
        'TERBLOKIR',
        answersJson
      ]);
      if (blkAttemptCol !== -1) {
        try {
          sheet.getRange(sheet.getLastRow(), blkAttemptCol + 1).setNumberFormat('@').setValue(String(normalizeExamAttempts(payload && payload.percobaan_ke)));
        } catch (eAttempt) {}
      }
    }
    return { success: true, message: 'Ujian berhasil diblokir.' };
  } catch (e) {
    return { success: false, message: 'Gagal memblokir ujian: ' + e.message };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Membuka blokir ujian siswa oleh Guru Pengampu / Kolaborator / Admin
 * Status diubah kembali menjadi DRAFT dan jumlah pelanggaran diset ke (max_violations - 1)
 * sehingga 1 pelanggaran berikutnya akan langsung memblokir siswa kembali.
 */
function unblockStudentExam(username, token, id_ujian, username_siswa) {
  if (!validateSession(username, token)) {
    return { success: false, message: 'Sesi tidak valid. Silakan login kembali.' };
  }
  
  var targetExamId = toSafeString(id_ujian).trim();
  var targetStudent = toSafeString(username_siswa).trim();
  if (!targetExamId || !targetStudent) {
    return { success: false, message: 'ID Ujian dan Username Siswa harus diisi.' };
  }
  
  var ss = getSpreadsheet();
  var usersSheet = getSheetSafe(ss, SHEETS.USERS);
  var examsSheet = getSheetSafe(ss, SHEETS.EXAMS);
  var resultsSheet = getSheetSafe(ss, SHEETS.RESULTS);
  
  if (!resultsSheet) {
    return { success: false, message: 'Sheet Results tidak ditemukan.' };
  }
  
  // 1. Verifikasi peran pemanggil
  var uData = usersSheet ? usersSheet.getDataRange().getValues() : [];
  var uMap = usersSheet ? getHeaderMap(usersSheet) : {};
  var callerRole = '';
  var uRoleCol = uMap['role'] !== undefined ? uMap['role'] : 4;
  for (var u = 1; u < uData.length; u++) {
    if (toSafeString(uData[u][1]).toLowerCase() === username.toLowerCase()) {
      callerRole = toSafeString(uData[u][uRoleCol]).toLowerCase();
      break;
    }
  }
  
  if (callerRole !== 'admin') {
    if (callerRole === 'guru' && examsSheet) {
      var eData = examsSheet.getDataRange().getValues();
      var eMap = getHeaderMap(examsSheet);
      var ecIdCol = eMap['id_ujian'] !== undefined ? eMap['id_ujian'] : 0;
      var ecGuruCol = eMap['id_guru'] !== undefined ? eMap['id_guru'] : 9;
      var ecKolabCol = eMap['kolaborator'] !== undefined ? eMap['kolaborator'] : -1;
      var hasAccess = false;
      for (var ec = 1; ec < eData.length; ec++) {
        if (toSafeString(eData[ec][ecIdCol]) === targetExamId) {
          var ecOwner = toSafeString(eData[ec][ecGuruCol]).trim().toLowerCase();
          var ecKolab = ecKolabCol !== -1 ? toSafeString(eData[ec][ecKolabCol]).trim().toLowerCase() : '';
          var ecKolabList = ecKolab ? ecKolab.split(',').map(function(s) { return s.trim(); }).filter(Boolean) : [];
          if (ecOwner === username.toLowerCase() || ecKolabList.indexOf(username.toLowerCase()) !== -1 || !ecOwner) {
            hasAccess = true;
          }
          break;
        }
      }
      if (!hasAccess) {
        return { success: false, message: 'Anda tidak memiliki hak akses untuk membuka blokir pada ujian ini.' };
      }
    } else {
      return { success: false, message: 'Akses ditolak.' };
    }
  }
  
  // 2. Ambil batas maksimal pelanggaran dari pengaturan UJIAN (kini unik per ujian)
  var maxViolations = 3;
  if (examsSheet) {
    var maxViolExamData = examsSheet.getDataRange().getValues();
    var maxViolExamMap = getHeaderMap(examsSheet);
    var maxViolExamIdCol = maxViolExamMap['id_ujian'] !== undefined ? maxViolExamMap['id_ujian'] : 0;
    var maxViolExamCol = maxViolExamMap['max_violations'] !== undefined ? maxViolExamMap['max_violations'] : -1;
    for (var mv = 1; mv < maxViolExamData.length; mv++) {
      if (toSafeString(maxViolExamData[mv][maxViolExamIdCol]) === targetExamId) {
        maxViolations = maxViolExamCol !== -1 ? normalizeExamMaxViolations(maxViolExamData[mv][maxViolExamCol]) : 3;
        break;
      }
    }
  }
  
  // Setel jumlah pelanggaran ke maxViolations - 1 agar jika melanggar 1x lagi langsung terblokir kembali
  var resetViolations = Math.max(0, maxViolations - 1);
  
  var lock = LockService.getScriptLock();
  if (!lock.tryLock(10000)) {
    return { success: false, message: 'Server sibuk saat memproses buka blokir.' };
  }
  
  try {
    var existingRecord = findAndConsolidateStudentResult(resultsSheet, targetExamId, targetStudent);
    if (!existingRecord) {
      return { success: false, message: 'Data hasil ujian siswa tidak ditemukan.' };
    }
    
    // Update status kembali ke DRAFT & reset pelanggaran ke (max - 1)
    resultsSheet.getRange(existingRecord.rowNumber, 8).setValue('DRAFT');
    resultsSheet.getRange(existingRecord.rowNumber, 6).setValue(resetViolations);
    
    // Perbarui waktu_mulai ke waktu sekarang agar sisa waktu pengerjaan siswa tidak hangus selama terblokir
    var nowStr = Utilities.formatDate(new Date(), Session.getScriptTimeZone() || 'Asia/Jakarta', 'yyyy-MM-dd HH:mm:ss');
    resultsSheet.getRange(existingRecord.rowNumber, 4).setValue(nowStr);
    
    logActivity(
      username,
      'Buka Blokir Siswa',
      'Buka blokir ujian ' + targetExamId + ' untuk siswa ' + targetStudent + ' | Pelanggaran diset ke ' + resetViolations
    );
    
    return {
      success: true,
      message: 'Blokir ujian siswa ' + targetStudent + ' berhasil dibuka. Siswa kini dapat melanjutkan ujian.'
    };
  } catch (err) {
    return { success: false, message: 'Gagal membuka blokir: ' + err.message };
  } finally {
    lock.releaseLock();
  }
}
