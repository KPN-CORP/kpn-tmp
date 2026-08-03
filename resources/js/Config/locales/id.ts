import type { LocaleMessages } from './index'

// Typed against `en` so the two dictionaries can't drift out of sync — a
// missing or misspelled key here is a compile-time error.
const id: LocaleMessages = {
    app: {
        name: 'KPN Console',
        version: 'v1.0.0',
    },

    nav: {
        main: 'Utama',
        management: 'Manajemen',
        system: 'Sistem',

        dashboard: 'Dasbor',
        users: 'Pengguna',
        reports: 'Laporan',
        settings: 'Pengaturan',
        profile: 'Profil',
        activity: 'Aktivitas',

        logout: 'Keluar',
    },

    topbar: {
        openMenu: 'Buka menu',
        closeMenu: 'Tutup menu',
        account: 'Akun',
        guest: 'Tamu',
    },

    auth: {
        loginTitle: 'Selamat datang kembali',
        loginSubtitle: 'Masuk untuk melanjutkan ke ruang kerja Anda',
        email: 'Email',
        password: 'Kata sandi',
        emailPlaceholder: 'anda@contoh.com',
        passwordPlaceholder: '••••••••',
        showPassword: 'Tampilkan kata sandi',
        hidePassword: 'Sembunyikan kata sandi',
        rememberMe: 'Ingat saya',
        forgotPassword: 'Lupa kata sandi?',
        signIn: 'Masuk',
        signingIn: 'Memproses…',
        forgotTitle: 'Lupa kata sandi',
        forgotSubtitle: 'Masukkan email Anda dan kami akan mengirim tautan reset',
        sendResetLink: 'Kirim tautan reset',
        sending: 'Mengirim…',
        backToLogin: 'Kembali ke halaman masuk',
        resetTitle: 'Atur ulang kata sandi',
        resetSubtitle: 'Pilih kata sandi baru untuk akun Anda',
        newPassword: 'Kata sandi baru',
        confirmPassword: 'Konfirmasi kata sandi',
        resetButton: 'Atur ulang kata sandi',
        resetting: 'Memproses…',
    },

    pagination: {
        rowsPerPage: 'Baris per halaman',
        of: 'dari',
        previous: 'Sebelumnya',
        next: 'Berikutnya',
    },

    dashboard: {
        title: 'Dasbor',
        subtitle: 'Ringkasan ruang kerja Anda',
        welcome: 'Selamat datang kembali',
        stats: {
            users: 'Total Pengguna',
            active: 'Sesi Aktif',
            reports: 'Laporan',
            revenue: 'Pendapatan',
        },
        table: {
            title: 'Data Terbaru',
            id: 'ID',
            name: 'Nama',
            email: 'Email',
            role: 'Peran',
            status: 'Status',
            createdAt: 'Dibuat',
        },
        status: {
            active: 'Aktif',
            pending: 'Menunggu',
            inactive: 'Nonaktif',
        },
    },

    common: {
        search: 'Cari',
        empty: 'Belum ada data',
        loading: 'Memuat…',
    },
}

export default id
