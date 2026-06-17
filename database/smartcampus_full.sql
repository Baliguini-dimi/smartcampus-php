-- =============================================================================
-- SmartCampus SaaS — smartcampus_full.sql
-- Schéma complet + données de test (2 tenants, étudiants, cours, notes)
-- Compatible MySQL 5.7+ / MariaDB 10.3+
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- Supprimer les tables existantes si besoin (re-import propre)
DROP TABLE IF EXISTS quiz_attempts, quiz_questions, quizzes,
                     ai_messages, ai_conversations,
                     activity_logs, grades, messages, announcements,
                     courses, students, professors, users, tenants;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- TENANTS
-- =============================================================================
CREATE TABLE tenants (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(150) NOT NULL,
    slug             VARCHAR(60)  NOT NULL UNIQUE,
    email            VARCHAR(150) NULL,
    phone            VARCHAR(30)  NULL,
    address          TEXT         NULL,
    logo             VARCHAR(255) NULL,
    primary_color    VARCHAR(7)   NOT NULL DEFAULT '#0b2b4f',
    secondary_color  VARCHAR(7)   NOT NULL DEFAULT '#ffb347',
    plan             ENUM('free','pro','enterprise') NOT NULL DEFAULT 'free',
    status           ENUM('active','suspended','inactive') NOT NULL DEFAULT 'active',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- USERS
-- =============================================================================
CREATE TABLE users (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT UNSIGNED NOT NULL,
    email            VARCHAR(150) NOT NULL,
    password         VARCHAR(255) NOT NULL,
    first_name       VARCHAR(80)  NOT NULL,
    last_name        VARCHAR(80)  NOT NULL,
    role             ENUM('admin','professor','student') NOT NULL DEFAULT 'student',
    status           ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    phone            VARCHAR(30)  NULL,
    address          TEXT         NULL,
    profile_picture  VARCHAR(255) NULL,
    last_login       DATETIME     NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_tenant (email, tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- STUDENTS
-- =============================================================================
CREATE TABLE students (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    matricule        VARCHAR(30)  NOT NULL,
    department       VARCHAR(100) NULL,
    field_of_study   VARCHAR(100) NULL,
    enrollment_date  DATE         NULL,
    status           ENUM('active','inactive','graduated','suspended') NOT NULL DEFAULT 'active',
    UNIQUE KEY uq_matricule_tenant (matricule, tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- PROFESSORS
-- =============================================================================
CREATE TABLE professors (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    employee_id      VARCHAR(30)  NULL,
    department       VARCHAR(100) NULL,
    specialization   VARCHAR(150) NULL,
    office_location  VARCHAR(100) NULL,
    office_hours     VARCHAR(200) NULL,
    hire_date        DATE         NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- COURSES
-- =============================================================================
CREATE TABLE courses (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT UNSIGNED NOT NULL,
    code             VARCHAR(20)  NOT NULL,
    title            VARCHAR(200) NOT NULL,
    description      TEXT         NULL,
    objectives       TEXT         NULL,
    syllabus         TEXT         NULL,
    credits          TINYINT UNSIGNED NOT NULL DEFAULT 3,
    department       VARCHAR(100) NULL,
    capacity         SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    professor_id     INT UNSIGNED NULL,
    semester         VARCHAR(30)  NULL,
    academic_year    VARCHAR(10)  NULL,
    status           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_code_tenant (code, tenant_id),
    FOREIGN KEY (tenant_id)   REFERENCES tenants(id)    ON DELETE CASCADE,
    FOREIGN KEY (professor_id) REFERENCES professors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- GRADES
-- =============================================================================
CREATE TABLE grades (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT UNSIGNED NOT NULL,
    student_id       INT UNSIGNED NOT NULL,
    course_id        INT UNSIGNED NOT NULL,
    professor_id     INT UNSIGNED NULL,
    assignment_score DECIMAL(5,2) NULL,
    midterm_score    DECIMAL(5,2) NULL,
    final_score      DECIMAL(5,2) NULL,
    score            DECIMAL(5,2) NULL COMMENT 'Moyenne calculée automatiquement',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_grade (student_id, course_id, tenant_id),
    FOREIGN KEY (tenant_id)  REFERENCES tenants(id)    ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id)   ON DELETE CASCADE,
    FOREIGN KEY (course_id)  REFERENCES courses(id)    ON DELETE CASCADE,
    FOREIGN KEY (professor_id) REFERENCES professors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- MESSAGES
-- =============================================================================
CREATE TABLE messages (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT UNSIGNED NOT NULL,
    sender_id        INT UNSIGNED NULL,
    recipient_id     INT UNSIGNED NOT NULL,
    subject          VARCHAR(200) NOT NULL,
    content          TEXT         NOT NULL,
    is_read          TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id)    REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id)    REFERENCES users(id)   ON DELETE SET NULL,
    FOREIGN KEY (recipient_id) REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- ANNOUNCEMENTS
-- =============================================================================
CREATE TABLE announcements (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT UNSIGNED NOT NULL,
    title            VARCHAR(200) NOT NULL,
    content          TEXT         NOT NULL,
    author_id        INT UNSIGNED NOT NULL,
    priority         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_published     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- AI CONVERSATIONS & MESSAGES
-- =============================================================================
CREATE TABLE ai_conversations (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    title            VARCHAR(150) NOT NULL DEFAULT 'Nouvelle conversation',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_messages (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id  INT UNSIGNED NOT NULL,
    role             ENUM('user','assistant') NOT NULL,
    content          TEXT NOT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- QUIZZES
-- =============================================================================
CREATE TABLE quizzes (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT UNSIGNED NOT NULL,
    course_id        INT UNSIGNED NOT NULL,
    title            VARCHAR(200) NOT NULL,
    num_questions    TINYINT UNSIGNED NOT NULL DEFAULT 5,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_questions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id          INT UNSIGNED NOT NULL,
    question         TEXT NOT NULL,
    option_a         VARCHAR(300) NOT NULL,
    option_b         VARCHAR(300) NOT NULL,
    option_c         VARCHAR(300) NOT NULL,
    option_d         VARCHAR(300) NOT NULL,
    correct_answer   CHAR(1) NOT NULL,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_attempts (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT UNSIGNED NOT NULL,
    quiz_id          INT UNSIGNED NOT NULL,
    student_id       INT UNSIGNED NOT NULL,
    score            DECIMAL(5,2) NOT NULL,
    correct          TINYINT UNSIGNED NOT NULL,
    total            TINYINT UNSIGNED NOT NULL,
    attempted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id)  REFERENCES tenants(id)  ON DELETE CASCADE,
    FOREIGN KEY (quiz_id)    REFERENCES quizzes(id)  ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- ACTIVITY LOGS (audit trail)
-- =============================================================================
CREATE TABLE activity_logs (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NULL,
    action           VARCHAR(80)  NOT NULL,
    description      VARCHAR(500) NOT NULL,
    entity           VARCHAR(60)  NULL,
    entity_id        INT UNSIGNED NULL,
    ip_address       VARCHAR(45)  NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_date (tenant_id, created_at),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- DONNÉES DE TEST
-- =============================================================================

-- Tenant 1 : IUA (plan pro)
INSERT INTO tenants (name, slug, email, primary_color, secondary_color, plan, status) VALUES
('Institut Universitaire d\'Abidjan', 'iua', 'contact@iua.ci', '#0b2b4f', '#ffb347', 'pro', 'active');

-- Tenant 2 : UVCI (plan free)
INSERT INTO tenants (name, slug, email, primary_color, secondary_color, plan, status) VALUES
('Université Virtuelle de Côte d\'Ivoire', 'uvci', 'contact@uvci.ci', '#1a5276', '#f39c12', 'free', 'active');

-- ---- USERS IUA (tenant_id = 1) ----
-- Mot de passe de tous les comptes de test : SmartCampus@2025
-- Hash bcrypt cost 12 : $2y$12$
INSERT INTO users (tenant_id, email, password, first_name, last_name, role, status, created_at) VALUES
(1, 'admin@iua.ci',         '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Kouassi', 'ADMIN',       'admin',     'active', NOW()),
(1, 'prof.kone@iua.ci',     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ibrahim', 'KONE',        'professor', 'active', NOW()),
(1, 'prof.diabate@iua.ci',  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mariam',  'DIABATE',     'professor', 'active', NOW()),
(1, 'etu.coulibaly@iua.ci', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Amara',   'COULIBALY',   'student',   'active', NOW()),
(1, 'etu.traore@iua.ci',    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Fatou',   'TRAORE',      'student',   'active', NOW()),
(1, 'etu.yao@iua.ci',       '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Konan',   'YAO',         'student',   'active', NOW()),
(1, 'etu.bamba@iua.ci',     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Seydou',  'BAMBA',       'student',   'active', NOW()),
(1, 'etu.ouattara@iua.ci',  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Aïcha',   'OUATTARA',    'student',   'active', NOW()),
(1, 'etu.toure@iua.ci',     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Moussa',  'TOURE',       'student',   'active', NOW()),
(1, 'etu.diallo@iua.ci',    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Kadiatou','DIALLO',      'student',   'active', NOW()),
(1, 'etu.n_guessan@iua.ci', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Adjoua',  'N\'GUESSAN',  'student',   'active', NOW()),
(1, 'etu.konan@iua.ci',     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Koffi',   'KONAN',       'student',   'active', NOW());

-- Professors IUA
INSERT INTO professors (tenant_id, user_id, employee_id, department, specialization, office_hours, hire_date) VALUES
(1, 2, 'PROF-2025-00002', 'Informatique',          'Big Data & IA',          'Lun-Mer 9h-12h', '2020-09-01'),
(1, 3, 'PROF-2025-00003', 'Mathématiques',         'Analyse & Probabilités', 'Mar-Jeu 14h-17h', '2019-01-15');

-- Students IUA
INSERT INTO students (tenant_id, user_id, matricule, department, field_of_study, enrollment_date, status) VALUES
(1, 4,  'STU-2025-00004', 'Informatique', 'Big Data & IA',     '2023-10-01', 'active'),
(1, 5,  'STU-2025-00005', 'Informatique', 'Génie Logiciel',    '2023-10-01', 'active'),
(1, 6,  'STU-2025-00006', 'Informatique', 'Big Data & IA',     '2023-10-01', 'active'),
(1, 7,  'STU-2025-00007', 'Informatique', 'Réseaux & Sécurité','2024-10-01', 'active'),
(1, 8,  'STU-2025-00008', 'Informatique', 'Big Data & IA',     '2024-10-01', 'active'),
(1, 9,  'STU-2025-00009', 'Informatique', 'Génie Logiciel',    '2023-10-01', 'active'),
(1, 10, 'STU-2025-00010', 'Informatique', 'Big Data & IA',     '2024-10-01', 'active'),
(1, 11, 'STU-2025-00011', 'Informatique', 'Génie Logiciel',    '2023-10-01', 'active'),
(1, 12, 'STU-2025-00012', 'Informatique', 'Réseaux & Sécurité','2024-10-01', 'active');

-- Courses IUA
INSERT INTO courses (tenant_id, code, title, department, credits, professor_id, semester, academic_year, status) VALUES
(1, 'INF401', 'Bases de données avancées',      'Informatique', 4, 1, 'S1', '2024-2025', 'active'),
(1, 'INF402', 'Machine Learning',               'Informatique', 4, 1, 'S1', '2024-2025', 'active'),
(1, 'INF403', 'Développement Web Full Stack',   'Informatique', 3, 2, 'S1', '2024-2025', 'active'),
(1, 'MAT401', 'Statistiques & Probabilités',    'Mathématiques',3, 2, 'S1', '2024-2025', 'active'),
(1, 'INF404', 'Sécurité des Systèmes',          'Informatique', 3, 1, 'S2', '2024-2025', 'active');

-- Grades IUA (notes variées)
INSERT INTO grades (tenant_id, student_id, course_id, professor_id, assignment_score, midterm_score, final_score, score) VALUES
(1, 1, 1, 1, 16.0, 14.5, 15.0, 15.17), (1, 1, 2, 1, 12.0, 11.0, 13.0, 12.00),
(1, 1, 3, 2, 18.0, 17.0, 16.5, 17.17), (1, 1, 4, 2, 14.0, 13.5, 15.0, 14.17),
(1, 2, 1, 1,  9.0,  8.5, 10.0,  9.17), (1, 2, 2, 1, 14.0, 15.0, 13.5, 14.17),
(1, 2, 3, 2, 11.0, 12.0, 10.5, 11.17), (1, 3, 1, 1, 17.0, 16.5, 18.0, 17.17),
(1, 3, 2, 1, 15.0, 14.0, 16.0, 15.00), (1, 3, 4, 2, 13.0, 12.5, 14.0, 13.17),
(1, 4, 1, 1,  7.0,  8.0,  6.5,  7.17), (1, 4, 3, 2, 10.0,  9.5, 11.0, 10.17),
(1, 5, 2, 1, 19.0, 18.5, 20.0, 19.17), (1, 5, 4, 2, 16.0, 15.5, 17.0, 16.17),
(1, 6, 1, 1, 12.0, 11.5, 13.0, 12.17), (1, 6, 3, 2, 14.5, 15.0, 13.5, 14.33),
(1, 7, 2, 1, 10.0,  9.0, 11.0, 10.00), (1, 7, 5, 1,  8.0,  7.5,  9.0,  8.17),
(1, 8, 1, 1, 16.5, 17.0, 15.5, 16.33), (1, 9, 3, 2, 13.0, 12.0, 14.0, 13.00);

-- Announcements IUA
INSERT INTO announcements (tenant_id, title, content, author_id, priority, is_published) VALUES
(1, 'Bienvenue sur SmartCampus !',
   'La plateforme SmartCampus est maintenant disponible pour tous les étudiants et professeurs de l\'IUA.',
   1, 2, 1),
(1, 'Calendrier des examens S1 2024-2025',
   'Les examens du premier semestre se dérouleront du 15 au 25 janvier 2025. Consultez votre emploi du temps.',
   1, 1, 1),
(1, 'Séance de formation IA — Groq & LLaMA',
   'Une séance de formation sur l\'utilisation des outils IA sera organisée le 10 janvier 2025 en salle B204.',
   2, 0, 1);

-- Message de bienvenue
INSERT INTO messages (tenant_id, sender_id, recipient_id, subject, content, is_read) VALUES
(1, 1, 4, 'Bienvenue sur SmartCampus',
 'Bonjour Amara, bienvenue sur la plateforme SmartCampus de l\'IUA ! N\'hésitez pas à consulter vos cours et vos notes. Bonne formation !',
 0);

