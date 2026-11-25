-- ============================================================================
--  CampusVote — Online Voting System
--  MySQL / MariaDB schema + demonstration data
-- ----------------------------------------------------------------------------
--  Import through phpMyAdmin or:
--      mysql -u root -p < database/database.sql
--
--  WARNING: This script drops the application tables if they already exist.
--  It is intended for a fresh installation or a clean academic demonstration.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE DATABASE IF NOT EXISTS `online_voting_system`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `online_voting_system`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `votes`;
DROP TABLE IF EXISTS `vote_participation`;
DROP TABLE IF EXISTS `candidates`;
DROP TABLE IF EXISTS `elections`;
DROP TABLE IF EXISTS `voters`;
DROP TABLE IF EXISTS `admins`;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
--  Administrators
-- ----------------------------------------------------------------------------
CREATE TABLE `admins` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name`     VARCHAR(100) NOT NULL,
    `username`      VARCHAR(50)  NOT NULL,
    `email`         VARCHAR(150) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login_at` DATETIME     NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admins_username` (`username`),
    UNIQUE KEY `uq_admins_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Registered voters
-- ----------------------------------------------------------------------------
CREATE TABLE `voters` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name`     VARCHAR(100) NOT NULL,
    `username`      VARCHAR(50)  NOT NULL,
    `email`         VARCHAR(150) NOT NULL,
    `student_id`    VARCHAR(30)  NOT NULL,
    `department`    VARCHAR(100) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login_at` DATETIME     NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_voters_username` (`username`),
    UNIQUE KEY `uq_voters_email` (`email`),
    UNIQUE KEY `uq_voters_student_id` (`student_id`),
    KEY `idx_voters_is_active` (`is_active`),
    KEY `idx_voters_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Elections
--  status:
--    draft  = being prepared, not visible for voting
--    active = scheduled or open; actual voting is gated by start_at/end_at
--    closed = manually ended by an administrator
-- ----------------------------------------------------------------------------
CREATE TABLE `elections` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`             VARCHAR(150) NOT NULL,
    `description`       TEXT         NULL,
    `start_at`          DATETIME     NOT NULL,
    `end_at`            DATETIME     NOT NULL,
    `status`            ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
    `results_published` TINYINT(1)   NOT NULL DEFAULT 0,
    `created_by`        INT UNSIGNED NULL,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_elections_status_window` (`status`, `start_at`, `end_at`),
    KEY `idx_elections_results` (`results_published`),
    KEY `fk_elections_admin` (`created_by`),
    CONSTRAINT `fk_elections_admin`
        FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_elections_window` CHECK (`end_at` > `start_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Candidates
-- ----------------------------------------------------------------------------
CREATE TABLE `candidates` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `election_id`   INT UNSIGNED NOT NULL,
    `full_name`     VARCHAR(100) NOT NULL,
    `party`         VARCHAR(100) NULL,
    `tagline`       VARCHAR(180) NULL,
    `bio`           TEXT         NULL,
    `manifesto`     TEXT         NULL,
    `photo_url`     VARCHAR(500) NULL,
    `display_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_candidates_election_name` (`election_id`, `full_name`),
    KEY `idx_candidates_ballot_order` (`election_id`, `display_order`, `id`),
    CONSTRAINT `fk_candidates_election`
        FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Anonymous ballots
--  Deliberately contains NO voter_id. A ballot records only election + choice.
-- ----------------------------------------------------------------------------
CREATE TABLE `votes` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `election_id`  INT UNSIGNED NOT NULL,
    `candidate_id` INT UNSIGNED NOT NULL,
    `cast_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_votes_election` (`election_id`),
    KEY `idx_votes_candidate` (`candidate_id`),
    KEY `idx_votes_cast_at` (`cast_at`),
    CONSTRAINT `fk_votes_election`
        FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_votes_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Participation ledger
--  Proves a voter took part without storing their choice. The UNIQUE key
--  (voter_id, election_id) is the database-level duplicate-vote guard.
-- ----------------------------------------------------------------------------
CREATE TABLE `vote_participation` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `voter_id`     INT UNSIGNED NOT NULL,
    `election_id`  INT UNSIGNED NOT NULL,
    `receipt_code` VARCHAR(24) NOT NULL,
    `cast_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_participation_voter_election` (`voter_id`, `election_id`),
    UNIQUE KEY `uq_participation_receipt` (`receipt_code`),
    KEY `idx_participation_election` (`election_id`),
    KEY `idx_participation_cast_at` (`cast_at`),
    CONSTRAINT `fk_participation_voter`
        FOREIGN KEY (`voter_id`) REFERENCES `voters` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_participation_election`
        FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Login rate limiting / audit trail of authentication attempts
-- ----------------------------------------------------------------------------
CREATE TABLE `login_attempts` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_type`    ENUM('admin','voter') NOT NULL,
    `identifier`   VARCHAR(191) NOT NULL,
    `ip_address`   VARCHAR(45)  NOT NULL,
    `success`      TINYINT(1)   NOT NULL DEFAULT 0,
    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_login_lookup` (`user_type`, `identifier`, `ip_address`, `attempted_at`),
    KEY `idx_login_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Administrator action log
-- ----------------------------------------------------------------------------
CREATE TABLE `audit_logs` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_id`    INT UNSIGNED NULL,
    `action`      VARCHAR(80)  NOT NULL,
    `description` VARCHAR(500) NOT NULL,
    `ip_address`  VARCHAR(45)  NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_admin` (`admin_id`),
    KEY `idx_audit_created` (`created_at`),
    CONSTRAINT `fk_audit_admin`
        FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  DEMONSTRATION DATA
--  Passwords (change these before any real use):
--      Administrator : Admin@12345
--      All voters    : Voter@12345
-- ============================================================================

INSERT INTO `admins`
    (`id`, `full_name`, `username`, `email`, `password_hash`, `is_active`)
VALUES
    (1, 'Election Commissioner', 'admin', 'admin@campusvote.test',
     '$2y$12$pZhiqQrsz8cpJVGev9K7..ivzRHKgyM0zb7G.wlUH5IbrxGjXhECa', 1);

INSERT INTO `voters`
    (`id`, `full_name`, `username`, `email`, `student_id`, `department`,
     `password_hash`, `is_active`)
VALUES
    (1, 'Aditi Sharma',  'aditi',  'aditi.sharma@campus.test',  'STU-2026-001', 'Computer Science',
     '$2y$12$1KYulMQYgTfbv0AQfMVCCORUUD19nQcFPTqTx66VfS5MBkPC1HIvu', 1),
    (2, 'Rahul Verma',   'rahul',  'rahul.verma@campus.test',   'STU-2026-002', 'Mechanical Engineering',
     '$2y$12$1KYulMQYgTfbv0AQfMVCCORUUD19nQcFPTqTx66VfS5MBkPC1HIvu', 1),
    (3, 'Meera Joshi',   'meera',  'meera.joshi@campus.test',   'STU-2026-003', 'Economics',
     '$2y$12$1KYulMQYgTfbv0AQfMVCCORUUD19nQcFPTqTx66VfS5MBkPC1HIvu', 1),
    (4, 'Karan Patel',   'karan',  'karan.patel@campus.test',   'STU-2026-004', 'Computer Science',
     '$2y$12$1KYulMQYgTfbv0AQfMVCCORUUD19nQcFPTqTx66VfS5MBkPC1HIvu', 1),
    (5, 'Simran Kaur',   'simran', 'simran.kaur@campus.test',   'STU-2026-005', 'Design',
     '$2y$12$1KYulMQYgTfbv0AQfMVCCORUUD19nQcFPTqTx66VfS5MBkPC1HIvu', 1),
    (6, 'Nikhil Reddy',  'nikhil', 'nikhil.reddy@campus.test',  'STU-2026-006', 'Commerce',
     '$2y$12$1KYulMQYgTfbv0AQfMVCCORUUD19nQcFPTqTx66VfS5MBkPC1HIvu', 1),
    (7, 'Fatima Sheikh', 'fatima', 'fatima.sheikh@campus.test', 'STU-2026-007', 'Political Science',
     '$2y$12$1KYulMQYgTfbv0AQfMVCCORUUD19nQcFPTqTx66VfS5MBkPC1HIvu', 1),
    (8, 'Yash Thakur',   'yash',   'yash.thakur@campus.test',   'STU-2026-008', 'Civil Engineering',
     '$2y$12$1KYulMQYgTfbv0AQfMVCCORUUD19nQcFPTqTx66VfS5MBkPC1HIvu', 1);

INSERT INTO `elections`
    (`id`, `title`, `description`, `start_at`, `end_at`, `status`,
     `results_published`, `created_by`)
VALUES
    (1, 'Student Council President 2026',
     'Choose the student who will lead the Student Council for the 2026–27 academic year. The President represents students to the administration, chairs council meetings, and leads the annual student agenda.',
     NOW() - INTERVAL 2 DAY, NOW() + INTERVAL 5 DAY, 'active', 0, 1),
    (2, 'Faculty Senate Representative',
     'Select the student representative who will sit on the Faculty Senate and carry academic concerns into formal senate discussions.',
     NOW() + INTERVAL 3 DAY, NOW() + INTERVAL 10 DAY, 'active', 0, 1),
    (3, 'Sports Council Secretary 2025',
     'The completed 2025 election for Sports Council Secretary. Results are published for the campus record.',
     NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 13 DAY, 'closed', 1, 1),
    (4, 'Alumni Advisory Poll',
     'A draft consultation to decide how the alumni advisory seat should be structured. This election is still being prepared.',
     NOW() + INTERVAL 15 DAY, NOW() + INTERVAL 21 DAY, 'draft', 0, 1);

INSERT INTO `candidates`
    (`id`, `election_id`, `full_name`, `party`, `tagline`, `bio`, `manifesto`, `display_order`)
VALUES
    (1, 1, 'Ayesha Khan', 'Students'' Progressive Front',
     'A fairer campus, built together.',
     'Third-year Economics student, two-time class representative, and coordinator of the campus peer-mentoring programme.',
     'If elected, I will prioritise transparent council budgets, a fixed monthly open forum with the administration, and a student support fund for emergency academic costs.',
     1),
    (2, 1, 'Rohan Mehta', 'Independent',
     'Practical ideas. Real follow-through.',
     'Final-year Mechanical Engineering student and former secretary of the Robotics Club.',
     'My plan focuses on lab access outside class hours, a transport shuttle pilot for evening classes, and a public dashboard that tracks every council promise to completion.',
     2),
    (3, 1, 'Priya Nair', 'Students for Change',
     'Every student counted.',
     'Second-year Design student and volunteer lead at the campus accessibility initiative.',
     'I am campaigning for an accessibility audit of all academic blocks, a confidential grievance portal, and a council seat reserved for first-year students.',
     3),
    (4, 2, 'Kabir Shah', 'Academic Renewal Panel',
     'Make the senate hear every department.',
     'Fourth-year Political Science student and debate society captain.',
     'I will bring a written departmental agenda to every senate meeting, publish plain-language summaries within 48 hours, and run open office hours twice a month.',
     1),
    (5, 2, 'Ananya Rao', 'Independent',
     'Quiet work, clear outcomes.',
     'Third-year Physics student and undergraduate research assistant.',
     'My priorities are predictable assessment schedules, research stipend transparency, and a simple process for appealing academic decisions.',
     2),
    (6, 2, 'Dev Malhotra', 'Student Voice Alliance',
     'A stronger bridge between students and faculty.',
     'Second-year Commerce student and coordinator of the campus financial-literacy clinic.',
     'I will push for published faculty feedback metrics, a cross-department credit guide, and student representation on curriculum review committees.',
     3),
    (7, 3, 'Arjun Verma', 'Team Spirit',
     'More courts. More teams. More students.',
     'Final-year Civil Engineering student and former basketball team captain.',
     'I will expand evening ground access, introduce inter-department leagues for underrepresented sports, and publish a transparent equipment budget.',
     1),
    (8, 3, 'Neha Gupta', 'Field Forward',
     'Sport should be open to everyone.',
     'Third-year Design student and organiser of the campus annual athletics meet.',
     'I am committed to equal coaching support for women''s teams, accessible sporting facilities, and a simple booking system for practice slots.',
     2),
    (9, 3, 'Sameer Khan', 'Independent',
     'Weekend games, better facilities.',
     'Second-year Commerce student and campus football league organiser.',
     'My goals are weekend transport for away matches, first-aid training for team managers, and an equipment repair and reuse programme.',
     3),
    (10, 3, 'Riya Das', 'Play Fair',
     'Fair selection, every season.',
     'First-year Computer Science student and varsity swimmer.',
     'I will make team selection criteria public, create a grievance channel for selection disputes, and reduce the cost of participating in inter-campus events.',
     4),
    (11, 4, 'Ishaan Bose', 'Alumni Connect',
     'A seat that listens to students.',
     'Alumnus and former Student Council president.',
     'A proposal to create a structured alumni mentoring programme with clear student representation and published engagement goals.',
     1),
    (12, 4, 'Tara Menon', 'Independent',
     'Mentorship without the paperwork.',
     'Alumna and early-stage education researcher.',
     'A proposal for lightweight, opt-in mentorship circles focused on internships, research, and career exploration.',
     2);

-- Anonymous demonstration ballots.
INSERT INTO `votes` (`election_id`, `candidate_id`, `cast_at`) VALUES
    (1, 1, NOW() - INTERVAL 1 DAY),
    (1, 1, NOW() - INTERVAL 20 HOUR),
    (1, 2, NOW() - INTERVAL 10 HOUR),
    (1, 3, NOW() - INTERVAL 2 HOUR),
    (3, 7, NOW() - INTERVAL 18 DAY),
    (3, 7, NOW() - INTERVAL 18 DAY - INTERVAL 1 HOUR),
    (3, 7, NOW() - INTERVAL 17 DAY),
    (3, 8, NOW() - INTERVAL 17 DAY - INTERVAL 2 HOUR),
    (3, 8, NOW() - INTERVAL 16 DAY),
    (3, 9, NOW() - INTERVAL 16 DAY - INTERVAL 2 HOUR),
    (3, 9, NOW() - INTERVAL 15 DAY),
    (3, 10, NOW() - INTERVAL 14 DAY);

-- Participation ledger matching the anonymous ballots above.
INSERT INTO `vote_participation`
    (`voter_id`, `election_id`, `receipt_code`, `cast_at`)
VALUES
    (1, 1, 'OVS-7A45-1B2C-9D01', NOW() - INTERVAL 1 DAY),
    (2, 1, 'OVS-8B56-2C3D-AE02', NOW() - INTERVAL 20 HOUR),
    (3, 1, 'OVS-9C67-3D4E-BF03', NOW() - INTERVAL 10 HOUR),
    (4, 1, 'OVS-AD78-4E5F-C104', NOW() - INTERVAL 2 HOUR),
    (1, 3, 'OVS-E189-5F60-D205', NOW() - INTERVAL 18 DAY),
    (2, 3, 'OVS-F29A-6071-E306', NOW() - INTERVAL 18 DAY - INTERVAL 1 HOUR),
    (3, 3, 'OVS-03AB-7182-F407', NOW() - INTERVAL 17 DAY),
    (4, 3, 'OVS-14BC-8293-0508', NOW() - INTERVAL 17 DAY - INTERVAL 2 HOUR),
    (5, 3, 'OVS-25CD-93A4-1609', NOW() - INTERVAL 16 DAY),
    (6, 3, 'OVS-36DE-A4B5-270A', NOW() - INTERVAL 16 DAY - INTERVAL 2 HOUR),
    (7, 3, 'OVS-47EF-B5C6-380B', NOW() - INTERVAL 15 DAY),
    (8, 3, 'OVS-58F0-C6D7-490C', NOW() - INTERVAL 14 DAY);

-- Keep AUTO_INCREMENT values tidy after inserting explicit sample ids.
ALTER TABLE `admins` AUTO_INCREMENT = 2;
ALTER TABLE `voters` AUTO_INCREMENT = 9;
ALTER TABLE `elections` AUTO_INCREMENT = 5;
ALTER TABLE `candidates` AUTO_INCREMENT = 13;
ALTER TABLE `votes` AUTO_INCREMENT = 13;
ALTER TABLE `vote_participation` AUTO_INCREMENT = 13;
