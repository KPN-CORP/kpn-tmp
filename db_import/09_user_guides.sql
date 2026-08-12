SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
USE `hcispanel_kpn_tmp`;

INSERT INTO `user_guides` (`id`, `title`, `description`, `file_path`, `file_name`, `file_size`, `target_role`, `uploaded_by`, `created_at`, `updated_at`) VALUES
(25, '[Talent Management Platform] Admins Guideline', NULL, 'user_guides/1769066415_[Talent Management Platform] Admins Guideline (1)(pdfgear.com).pdf', '[Talent Management Platform] Admins Guideline (1)(pdfgear.com).pdf', '2005.39 KB', 'admin', 72036, '2026-01-22 07:20:15', '2026-01-22 07:20:15'),
(26, '[Talent Management Platform] Superiors Guideline', NULL, 'user_guides/1769066471_[Talent Management Platform] Superiors Guideline.pdf', '[Talent Management Platform] Superiors Guideline.pdf', '1945.97 KB', 'manager', 72036, '2026-01-22 07:21:11', '2026-01-22 07:21:11'),
(29, '[Talent Management Platform] Subordinates/Ownself Guideline', NULL, 'user_guides/1769066598_[Talent Management Platform] Subordinates_Ownself Guideline.pdf', '[Talent Management Platform] Subordinates_Ownself Guideline.pdf', '1613.8 KB', 'all', 72036, '2026-01-22 07:23:18', '2026-01-22 07:23:18');

SET FOREIGN_KEY_CHECKS=1;
