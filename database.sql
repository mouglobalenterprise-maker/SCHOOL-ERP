-- ============================================================
-- EduManage Pro — Complete School ERP Database (FIXED)
-- Version: 1.1
-- ============================================================
-- LOGIN CREDENTIALS:
--   Admin:     username=admin     password=admin123
--   Teacher:   username=TCH001    password=teacher123
--   Teacher:   username=TCH002    password=teacher123
--   Teacher:   username=TCH003    password=teacher123
--   Student:   username=STU001    password=student123
--   Student:   username=STU002    password=student123
--   Secretary: username=sec001    password=sec123
-- ============================================================
-- NOTE: The hashes below were generated with PHP:
--   password_hash('admin123',   PASSWORD_BCRYPT)
--   password_hash('teacher123', PASSWORD_BCRYPT)
--   password_hash('student123', PASSWORD_BCRYPT)
--   password_hash('sec123',     PASSWORD_BCRYPT)
-- If hashes fail on your server, use the fix_passwords.php
-- script included with this package.
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `edumanage_pro`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `edumanage_pro`;

-- ============================================================
-- TABLE: roles
-- ============================================================
CREATE TABLE `roles` (
  `id`          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(50)      NOT NULL UNIQUE,
  `description` VARCHAR(255)     DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `roles` (`name`, `description`) VALUES
('admin',     'Full system access'),
('teacher',   'Academic operations only'),
('student',   'Read-only student/parent portal'),
('secretary', 'Fee and payment management only');

-- ============================================================
-- TABLE: users
-- NOTE ON PASSWORDS: Stored as bcrypt hashes.
-- The INSERT below uses a placeholder hash that your
-- fix_passwords.php script will replace on first run.
-- ============================================================
CREATE TABLE `users` (
  `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `username`   VARCHAR(50)      NOT NULL UNIQUE,
  `password`   VARCHAR(255)     NOT NULL,
  `role_id`    TINYINT UNSIGNED NOT NULL,
  `full_name`  VARCHAR(100)     NOT NULL,
  `email`      VARCHAR(100)     DEFAULT NULL,
  `phone`      VARCHAR(20)      DEFAULT NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `last_login` DATETIME         DEFAULT NULL,
  `created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_role`     (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: settings
-- ============================================================
CREATE TABLE `settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_val` TEXT         DEFAULT NULL,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`setting_key`, `setting_val`) VALUES
('school_name',       'Excellence Secondary School'),
('school_address',    '12 Education Avenue, Banjul, The Gambia'),
('school_phone',      '+220 700 0000'),
('school_email',      'info@excellenceschool.edu.gm'),
('school_motto',      'Knowledge is Power'),
('current_session',   '2024/2025'),
('current_term',      'First'),
('currency',          'GMD'),
('currency_symbol',   'D'),
('school_logo',       ''),
('principal_sig',     ''),
('class_label',       'Class'),
('results_test_max',  '20'),
('results_asn_max',   '20'),
('results_exam_max',  '60');

-- ============================================================
-- TABLE: academic_sessions
-- ============================================================
CREATE TABLE `academic_sessions` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(20)  NOT NULL UNIQUE,
  `is_current` TINYINT(1)   NOT NULL DEFAULT 0,
  `start_date` DATE         DEFAULT NULL,
  `end_date`   DATE         DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `academic_sessions` (`name`, `is_current`) VALUES
('2023/2024', 0),
('2024/2025', 1);

-- ============================================================
-- TABLE: terms
-- ============================================================
CREATE TABLE `terms` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(20)  NOT NULL,
  `session_id` INT UNSIGNED NOT NULL,
  `is_current` TINYINT(1)   NOT NULL DEFAULT 0,
  `start_date` DATE         DEFAULT NULL,
  `end_date`   DATE         DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`),
  CONSTRAINT `fk_terms_session` FOREIGN KEY (`session_id`) REFERENCES `academic_sessions`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `terms` (`name`, `session_id`, `is_current`) VALUES
('First',  2, 1),
('Second', 2, 0),
('Third',  2, 0);

-- ============================================================
-- TABLE: classes
-- ============================================================
CREATE TABLE `classes` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(50)  NOT NULL UNIQUE,
  `description` VARCHAR(255) DEFAULT NULL,
  `capacity`    INT UNSIGNED DEFAULT 40,
  `sort_order`  INT UNSIGNED DEFAULT 0,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `classes` (`name`, `sort_order`) VALUES
('JSS1', 1), ('JSS2', 2), ('JSS3', 3),
('SS1',  4), ('SS2',  5), ('SS3',  6);

-- ============================================================
-- TABLE: subjects
-- ============================================================
CREATE TABLE `subjects` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL UNIQUE,
  `code`       VARCHAR(10)  DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `subjects` (`name`, `code`) VALUES
('Mathematics',       'MTH'),
('English Language',  'ENG'),
('Biology',           'BIO'),
('Physics',           'PHY'),
('Chemistry',         'CHE'),
('History',           'HIS'),
('Geography',         'GEO'),
('Economics',         'ECO'),
('Computer Science',  'CSC'),
('French',            'FRN'),
('Religious Studies', 'CRS'),
('Physical Education','P.E');

-- ============================================================
-- TABLE: class_subjects
-- ============================================================
CREATE TABLE `class_subjects` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `class_id`   INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_class_subject` (`class_id`,`subject_id`),
  CONSTRAINT `fk_cs_class`   FOREIGN KEY (`class_id`)   REFERENCES `classes`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cs_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: teachers
-- ============================================================
CREATE TABLE `teachers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL UNIQUE,
  `teacher_code`  VARCHAR(20)  NOT NULL UNIQUE,
  `qualification` VARCHAR(100) DEFAULT NULL,
  `address`       TEXT         DEFAULT NULL,
  `photo`         VARCHAR(255) DEFAULT NULL,
  `joined_date`   DATE         DEFAULT NULL,
  `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_teacher_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: teacher_subjects
-- ============================================================
CREATE TABLE `teacher_subjects` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `teacher_id` INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  `class_id`   INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tsc` (`teacher_id`,`subject_id`,`class_id`),
  CONSTRAINT `fk_ts_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ts_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ts_class`   FOREIGN KEY (`class_id`)   REFERENCES `classes`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: students
-- ============================================================
CREATE TABLE `students` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED DEFAULT NULL,
  `student_id`      VARCHAR(20)  NOT NULL UNIQUE,
  `full_name`       VARCHAR(100) NOT NULL,
  `gender`          ENUM('Male','Female') NOT NULL,
  `dob`             DATE         DEFAULT NULL,
  `class_id`        INT UNSIGNED NOT NULL,
  `session_id`      INT UNSIGNED NOT NULL,
  `parent_name`     VARCHAR(100) DEFAULT NULL,
  `parent_phone1`   VARCHAR(20)  NOT NULL,
  `parent_phone2`   VARCHAR(20)  NOT NULL,
  `parent_email`    VARCHAR(100) DEFAULT NULL,
  `address`         TEXT         DEFAULT NULL,
  `photo`           VARCHAR(255) DEFAULT NULL,
  `blood_group`     VARCHAR(5)   DEFAULT NULL,
  `medical_notes`   TEXT         DEFAULT NULL,
  `status`          ENUM('active','inactive','graduated','transferred') NOT NULL DEFAULT 'active',
  `enrolled_date`   DATE         DEFAULT NULL,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_class`      (`class_id`),
  KEY `idx_session`    (`session_id`),
  CONSTRAINT `fk_student_class`   FOREIGN KEY (`class_id`)   REFERENCES `classes`(`id`),
  CONSTRAINT `fk_student_session` FOREIGN KEY (`session_id`) REFERENCES `academic_sessions`(`id`),
  CONSTRAINT `fk_student_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: grade_ranges
-- ============================================================
CREATE TABLE `grade_ranges` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `grade`  VARCHAR(5)   NOT NULL,
  `min`    TINYINT UNSIGNED NOT NULL,
  `max`    TINYINT UNSIGNED NOT NULL,
  `remark` VARCHAR(50)  NOT NULL,
  `points` DECIMAL(3,1) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `grade_ranges` (`grade`,`min`,`max`,`remark`,`points`) VALUES
('A',  80, 100, 'Excellent',  4.0),
('B',  70,  79, 'Very Good',  3.0),
('C',  60,  69, 'Good',       2.0),
('D',  50,  59, 'Pass',       1.0),
('F',   0,  49, 'Fail',       0.0);

-- ============================================================
-- TABLE: results
-- ============================================================
CREATE TABLE `results` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id`       INT UNSIGNED NOT NULL,
  `subject_id`       INT UNSIGNED NOT NULL,
  `class_id`         INT UNSIGNED NOT NULL,
  `term_id`          INT UNSIGNED NOT NULL,
  `session_id`       INT UNSIGNED NOT NULL,
  `teacher_id`       INT UNSIGNED DEFAULT NULL,
  `test_score`       DECIMAL(5,2) DEFAULT 0,
  `assignment_score` DECIMAL(5,2) DEFAULT 0,
  `exam_score`       DECIMAL(5,2) DEFAULT 0,
  `total_score`      DECIMAL(5,2) GENERATED ALWAYS AS (`test_score`+`assignment_score`+`exam_score`) STORED,
  `grade`            VARCHAR(5)   DEFAULT NULL,
  `remark`           VARCHAR(50)  DEFAULT NULL,
  `teacher_comment`  TEXT         DEFAULT NULL,
  `position`         INT UNSIGNED DEFAULT NULL,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_result` (`student_id`,`subject_id`,`term_id`,`session_id`),
  CONSTRAINT `fk_result_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_result_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_result_class`   FOREIGN KEY (`class_id`)   REFERENCES `classes`(`id`),
  CONSTRAINT `fk_result_term`    FOREIGN KEY (`term_id`)    REFERENCES `terms`(`id`),
  CONSTRAINT `fk_result_session` FOREIGN KEY (`session_id`) REFERENCES `academic_sessions`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: attendance
-- ============================================================
CREATE TABLE `attendance` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` INT UNSIGNED NOT NULL,
  `class_id`   INT UNSIGNED NOT NULL,
  `term_id`    INT UNSIGNED NOT NULL,
  `date`       DATE         NOT NULL,
  `status`     ENUM('present','absent','late') NOT NULL DEFAULT 'present',
  `note`       VARCHAR(255) DEFAULT NULL,
  `marked_by`  INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance` (`student_id`,`date`),
  CONSTRAINT `fk_att_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_att_class`   FOREIGN KEY (`class_id`)   REFERENCES `classes`(`id`),
  CONSTRAINT `fk_att_term`    FOREIGN KEY (`term_id`)    REFERENCES `terms`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: announcements
-- ============================================================
CREATE TABLE `announcements` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(200) NOT NULL,
  `body`       TEXT         NOT NULL,
  `priority`   ENUM('high','normal','low') NOT NULL DEFAULT 'normal',
  `target`     ENUM('all','students','teachers','parents') NOT NULL DEFAULT 'all',
  `posted_by`  INT UNSIGNED NOT NULL,
  `expires_at` DATE         DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ann_user` FOREIGN KEY (`posted_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: messages
-- ============================================================
CREATE TABLE `messages` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_user`    INT UNSIGNED NOT NULL,
  `to_user`      INT UNSIGNED DEFAULT NULL,
  `subject`      VARCHAR(200) NOT NULL,
  `body`         TEXT         NOT NULL,
  `is_broadcast` TINYINT(1)   NOT NULL DEFAULT 0,
  `is_read`      TINYINT(1)   NOT NULL DEFAULT 0,
  `read_at`      DATETIME     DEFAULT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_msg_from` FOREIGN KEY (`from_user`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_msg_to`   FOREIGN KEY (`to_user`)   REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: assignments
-- ============================================================
CREATE TABLE `assignments` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(200) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `subject_id`  INT UNSIGNED NOT NULL,
  `class_id`    INT UNSIGNED NOT NULL,
  `teacher_id`  INT UNSIGNED NOT NULL,
  `term_id`     INT UNSIGNED NOT NULL,
  `file_path`   VARCHAR(255) DEFAULT NULL,
  `due_date`    DATE         NOT NULL,
  `max_score`   DECIMAL(5,2) DEFAULT 20,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_asn_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`),
  CONSTRAINT `fk_asn_class`   FOREIGN KEY (`class_id`)   REFERENCES `classes`(`id`),
  CONSTRAINT `fk_asn_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`),
  CONSTRAINT `fk_asn_term`    FOREIGN KEY (`term_id`)    REFERENCES `terms`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: assignment_submissions
-- ============================================================
CREATE TABLE `assignment_submissions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_id` INT UNSIGNED NOT NULL,
  `student_id`    INT UNSIGNED NOT NULL,
  `file_path`     VARCHAR(255) DEFAULT NULL,
  `comment`       TEXT         DEFAULT NULL,
  `score`         DECIMAL(5,2) DEFAULT NULL,
  `graded_at`     DATETIME     DEFAULT NULL,
  `submitted_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submission` (`assignment_id`,`student_id`),
  CONSTRAINT `fk_sub_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_student`    FOREIGN KEY (`student_id`)    REFERENCES `students`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: timetable
-- ============================================================
CREATE TABLE `timetable` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `class_id`   INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED DEFAULT NULL,
  `teacher_id` INT UNSIGNED DEFAULT NULL,
  `day`        ENUM('Monday','Tuesday','Wednesday','Thursday','Friday') NOT NULL,
  `period`     TINYINT UNSIGNED NOT NULL,
  `start_time` TIME         DEFAULT NULL,
  `end_time`   TIME         DEFAULT NULL,
  `is_break`   TINYINT(1)   NOT NULL DEFAULT 0,
  `label`      VARCHAR(50)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_timetable` (`class_id`,`day`,`period`),
  CONSTRAINT `fk_tt_class`   FOREIGN KEY (`class_id`)   REFERENCES `classes`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tt_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tt_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: payments
-- ============================================================
CREATE TABLE `payments` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `payment_code`   VARCHAR(20)   NOT NULL UNIQUE,
  `student_id`     INT UNSIGNED  NOT NULL,
  `term_id`        INT UNSIGNED  NOT NULL,
  `session_id`     INT UNSIGNED  NOT NULL,
  `fee_type`       VARCHAR(100)  NOT NULL DEFAULT 'School Fees',
  `amount_due`     DECIMAL(10,2) NOT NULL DEFAULT 0,
  `amount_paid`    DECIMAL(10,2) NOT NULL DEFAULT 0,
  `balance`        DECIMAL(10,2) GENERATED ALWAYS AS (`amount_due`-`amount_paid`) STORED,
  `payment_date`   DATE          DEFAULT NULL,
  `payment_method` VARCHAR(50)   DEFAULT 'Cash',
  `receipt_no`     VARCHAR(50)   DEFAULT NULL,
  `status`         ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'unpaid',
  `notes`          TEXT          DEFAULT NULL,
  `recorded_by`    INT UNSIGNED  DEFAULT NULL,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_pay_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pay_term`    FOREIGN KEY (`term_id`)    REFERENCES `terms`(`id`),
  CONSTRAINT `fk_pay_session` FOREIGN KEY (`session_id`) REFERENCES `academic_sessions`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: documents
-- ============================================================
CREATE TABLE `documents` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(200) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `file_path`   VARCHAR(255) NOT NULL,
  `file_type`   VARCHAR(50)  DEFAULT NULL,
  `file_size`   INT UNSIGNED DEFAULT NULL,
  `subject_id`  INT UNSIGNED DEFAULT NULL,
  `class_id`    INT UNSIGNED DEFAULT NULL,
  `uploaded_by` INT UNSIGNED NOT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_doc_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_doc_class`   FOREIGN KEY (`class_id`)   REFERENCES `classes`(`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_doc_user`    FOREIGN KEY (`uploaded_by`)REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: notifications
-- ============================================================
CREATE TABLE `notifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `title`      VARCHAR(200) NOT NULL,
  `body`       TEXT         NOT NULL,
  `type`       VARCHAR(50)  DEFAULT 'info',
  `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
  `link`       VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: audit_logs
-- ============================================================
CREATE TABLE `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED    DEFAULT NULL,
  `username`    VARCHAR(50)     DEFAULT NULL,
  `action`      VARCHAR(100)    NOT NULL,
  `module`      VARCHAR(50)     NOT NULL,
  `description` TEXT            DEFAULT NULL,
  `ip_address`  VARCHAR(45)     DEFAULT NULL,
  `user_agent`  VARCHAR(255)    DEFAULT NULL,
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_module`  (`module`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: promotions
-- ============================================================
CREATE TABLE `promotions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id`    INT UNSIGNED NOT NULL,
  `from_class_id` INT UNSIGNED NOT NULL,
  `to_class_id`   INT UNSIGNED DEFAULT NULL,
  `from_session`  INT UNSIGNED NOT NULL,
  `to_session`    INT UNSIGNED DEFAULT NULL,
  `action`        ENUM('promoted','retained','graduated','transferred') NOT NULL DEFAULT 'promoted',
  `promoted_by`   INT UNSIGNED NOT NULL,
  `notes`         TEXT         DEFAULT NULL,
  `promoted_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_promo_student`      FOREIGN KEY (`student_id`)    REFERENCES `students`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_promo_from_class`   FOREIGN KEY (`from_class_id`) REFERENCES `classes`(`id`),
  CONSTRAINT `fk_promo_from_session` FOREIGN KEY (`from_session`)  REFERENCES `academic_sessions`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- USERS — passwords inserted as PLAIN TEXT markers.
-- Run fix_passwords.php after import to hash them properly.
-- OR replace these with hashes from your own PHP:
--   echo password_hash('admin123', PASSWORD_BCRYPT);
-- ============================================================
INSERT INTO `users` (`username`,`password`,`role_id`,`full_name`,`email`,`phone`,`status`) VALUES
('admin',  'PLAIN:admin123',   1, 'System Administrator', 'admin@school.edu',  '2207000000', 'active'),
('TCH001', 'PLAIN:teacher123', 2, 'Mr. Smith',            'smith@school.edu',   '2207100001', 'active'),
('TCH002', 'PLAIN:teacher123', 2, 'Mrs. Johnson',         'johnson@school.edu', '2207100002', 'active'),
('TCH003', 'PLAIN:teacher123', 2, 'Mr. Diallo',           'diallo@school.edu',  '2207100003', 'active'),
('STU001', 'PLAIN:student123', 3, 'John Doe',             NULL,                 '2207000001', 'active'),
('STU002', 'PLAIN:student123', 3, 'Amara Okonkwo',        NULL,                 '2207000003', 'active'),
('sec001', 'PLAIN:sec123',     4, 'Ms. Adams',            'adams@school.edu',   '2207200001', 'active');

-- Teachers
INSERT INTO `teachers` (`user_id`,`teacher_code`,`qualification`,`joined_date`,`status`) VALUES
(2, 'TCH001', 'B.Sc Mathematics',       '2020-09-01', 'active'),
(3, 'TCH002', 'B.A English Literature', '2019-01-15', 'active'),
(4, 'TCH003', 'B.Sc Biology',           '2021-03-01', 'active');

-- Teacher subjects
INSERT INTO `teacher_subjects` (`teacher_id`,`subject_id`,`class_id`) VALUES
(1,1,4),(1,1,5),(1,1,6),
(2,2,4),(2,2,5),
(3,3,1),(3,3,2);

-- Students
INSERT INTO `students`
  (`student_id`,`full_name`,`gender`,`dob`,`class_id`,`session_id`,`parent_name`,`parent_phone1`,`parent_phone2`,`address`,`status`,`enrolled_date`,`user_id`)
VALUES
('STU001','John Doe',      'Male',  '2008-03-12',4,2,'Mr. Doe',     '2207000001','2207000002','12 Main St, Banjul',     'active','2022-09-01',5),
('STU002','Amara Okonkwo', 'Female','2007-07-22',5,2,'Mrs. Okonkwo','2207000003','2207000004','45 Park Ave, Banjul',    'active','2021-09-01',6),
('STU003','Fatima Bah',    'Female','2010-01-15',1,2,'Mr. Bah',     '2207000005','2207000006','8 River Rd, Brikama',    'active','2024-09-01',NULL),
('STU004','Kofi Mensah',   'Male',  '2009-09-05',2,2,'Mrs. Mensah', '2207000007','2207000008','3 Hill Close, Serekunda','inactive','2023-09-01',NULL),
('STU005','Grace Kamara',  'Female','2006-11-30',6,2,'Mr. Kamara',  '2207000009','2207000010','21 Oak St, Banjul',      'active','2020-09-01',NULL);

-- Results
INSERT INTO `results` (`student_id`,`subject_id`,`class_id`,`term_id`,`session_id`,`teacher_id`,`test_score`,`assignment_score`,`exam_score`,`grade`,`remark`) VALUES
(1,1,4,1,2,1,18,15,62,'A','Excellent'),
(1,2,4,1,2,2,14,12,55,'B','Very Good'),
(1,3,4,1,2,3,10, 8,40,'D','Pass'),
(2,1,5,1,2,1,20,18,70,'A','Excellent'),
(2,2,5,1,2,2,16,14,58,'B','Very Good'),
(5,1,6,1,2,1,17,16,65,'A','Excellent'),
(5,2,6,1,2,2,15,14,60,'B','Very Good');

-- Attendance
INSERT INTO `attendance` (`student_id`,`class_id`,`term_id`,`date`,`status`,`marked_by`) VALUES
(1,4,1,'2025-04-09','present',2),(2,5,1,'2025-04-09','absent',2),
(3,1,1,'2025-04-09','late',2),  (4,2,1,'2025-04-09','present',2),
(5,6,1,'2025-04-09','present',2),(1,4,1,'2025-04-08','present',2),
(2,5,1,'2025-04-08','present',2),(3,1,1,'2025-04-08','present',2),
(4,2,1,'2025-04-08','absent',2), (5,6,1,'2025-04-08','present',2);

-- Announcements
INSERT INTO `announcements` (`title`,`body`,`priority`,`target`,`posted_by`) VALUES
('End of Term Exams','End of term examinations begin April 20, 2025.','high','all',1),
('Sports Day 2025','Annual sports day is scheduled for April 15.','normal','all',1),
('Fee Payment Reminder','All outstanding fees must be cleared before exams.','high','parents',7);

-- Payments
INSERT INTO `payments` (`payment_code`,`student_id`,`term_id`,`session_id`,`fee_type`,`amount_due`,`amount_paid`,`payment_date`,`receipt_no`,`status`,`recorded_by`) VALUES
('PAY-2025-001',1,1,2,'School Fees',5000.00,5000.00,'2025-01-10','RCT001','paid',7),
('PAY-2025-002',2,1,2,'School Fees',5000.00,2500.00,'2025-01-12','RCT002','partial',7),
('PAY-2025-003',3,1,2,'School Fees',4500.00,0.00,NULL,NULL,'unpaid',7),
('PAY-2025-004',4,1,2,'School Fees',4500.00,0.00,NULL,NULL,'unpaid',7),
('PAY-2025-005',5,1,2,'School Fees',5000.00,5000.00,'2025-01-08','RCT003','paid',7);

-- Messages
INSERT INTO `messages` (`from_user`,`to_user`,`subject`,`body`,`is_broadcast`,`is_read`) VALUES
(2,5,'Test Results','Well done on your mathematics test, John!',0,0),
(1,NULL,'Staff Meeting Friday','Meeting on Friday at 2PM in the conference room.',1,0);

-- Audit logs
INSERT INTO `audit_logs` (`user_id`,`username`,`action`,`module`,`description`,`ip_address`) VALUES
(1,'admin','Created student','Students','Added student STU005 Grace Kamara','127.0.0.1'),
(2,'TCH001','Marked attendance','Attendance','Marked attendance for SS1 on 2025-04-09','127.0.0.1'),
(7,'sec001','Recorded payment','Payments','Recorded payment PAY-2025-001 for STU001','127.0.0.1'),
(1,'admin','Updated result','Results','Updated Mathematics result for STU001','127.0.0.1');

-- Class Subjects mapping
INSERT INTO `class_subjects` (`class_id`,`subject_id`) VALUES
(4,1),(4,2),(4,3),(4,4),(4,5),(4,6),(4,7),(4,8),
(5,1),(5,2),(5,3),(5,4),(5,5),(5,6),(5,7),(5,8),
(6,1),(6,2),(6,3),(6,4),(6,5),(6,6),(6,7),(6,8),
(1,1),(1,2),(1,3),(1,6),(1,7),(1,11),
(2,1),(2,2),(2,3),(2,6),(2,7),(2,11),
(3,1),(3,2),(3,3),(3,6),(3,7),(3,11);

-- Timetable (SS1 sample)
INSERT INTO `timetable` (`class_id`,`subject_id`,`teacher_id`,`day`,`period`,`start_time`,`end_time`,`is_break`,`label`) VALUES
(4,1,1,'Monday',1,'07:30:00','08:20:00',0,NULL),
(4,2,2,'Monday',2,'08:20:00','09:10:00',0,NULL),
(4,NULL,NULL,'Monday',3,'09:10:00','09:30:00',1,'Break'),
(4,3,3,'Monday',4,'09:30:00','10:20:00',0,NULL),
(4,4,1,'Monday',5,'10:20:00','11:10:00',0,NULL),
(4,1,1,'Tuesday',1,'07:30:00','08:20:00',0,NULL),
(4,5,2,'Tuesday',2,'08:20:00','09:10:00',0,NULL),
(4,NULL,NULL,'Tuesday',3,'09:10:00','09:30:00',1,'Break'),
(4,6,3,'Tuesday',4,'09:30:00','10:20:00',0,NULL),
(4,2,2,'Tuesday',5,'10:20:00','11:10:00',0,NULL);

-- Notifications
INSERT INTO `notifications` (`user_id`,`title`,`body`,`type`) VALUES
(5,'New Result Posted','Your Mathematics result for First Term has been posted.','result'),
(5,'Attendance Alert','You were marked absent on 2025-04-08. Please see the office.','attendance'),
(1,'New Payment Received','Payment of GMD 5,000 received for student John Doe.','payment');
