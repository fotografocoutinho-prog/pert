-- ObraConnect 0.12.0 — SQL de instalação (esquema + dados base)
-- Gerado por: php artisan obraconnect:install-sql
--
-- Uso no cPanel sem SSH:
--   1. Crie a base de dados e o utilizador em "Bases de dados MySQL".
--   2. Abra o phpMyAdmin, escolha a base de dados vazia e use "Importar" com este ficheiro.
--   3. Configure o .env e abra /instalar para criar o super administrador.
-- Compatível com MySQL 8.0+ e MariaDB 10.6+ (utf8mb4).
-- Não contém dados pessoais nem dados de demonstração.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

create table `migrations` (`id` int unsigned not null auto_increment primary key, `migration` varchar(255) not null, `batch` int not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';

-- 0001_01_01_000000_create_users_table
create table `users` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(255) not null, `email` varchar(255) not null, `email_verified_at` timestamp null, `password` varchar(255) not null, `current_tenant_id` bigint unsigned null, `platform_role` varchar(20) null, `locale` varchar(10) not null default 'pt_PT', `is_active` tinyint(1) not null default '1', `last_login_at` timestamp null, `last_login_ip` varchar(45) null, `remember_token` varchar(100) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `users` add unique `users_email_unique`(`email`);
alter table `users` add index `users_current_tenant_id_index`(`current_tenant_id`);
alter table `users` add index `users_platform_role_index`(`platform_role`);
create table `password_reset_tokens` (`email` varchar(255) not null, `token` varchar(255) not null, `created_at` timestamp null, primary key (`email`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
create table `sessions` (`id` varchar(255) not null, `user_id` bigint unsigned null, `ip_address` varchar(45) null, `user_agent` text null, `payload` longtext not null, `last_activity` int not null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `sessions` add index `sessions_user_id_index`(`user_id`);
alter table `sessions` add index `sessions_last_activity_index`(`last_activity`);
insert into `migrations` (`migration`, `batch`) values ('0001_01_01_000000_create_users_table', 1);

-- 0001_01_01_000001_create_cache_table
create table `cache` (`key` varchar(255) not null, `value` mediumtext not null, `expiration` bigint not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `cache` add index `cache_expiration_index`(`expiration`);
create table `cache_locks` (`key` varchar(255) not null, `owner` varchar(255) not null, `expiration` bigint not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `cache_locks` add index `cache_locks_expiration_index`(`expiration`);
insert into `migrations` (`migration`, `batch`) values ('0001_01_01_000001_create_cache_table', 1);

-- 0001_01_01_000002_create_jobs_table
create table `jobs` (`id` bigint unsigned not null auto_increment primary key, `queue` varchar(255) not null, `payload` longtext not null, `attempts` smallint unsigned not null, `reserved_at` int unsigned null, `available_at` int unsigned not null, `created_at` int unsigned not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `jobs` add index `jobs_queue_index`(`queue`);
create table `job_batches` (`id` varchar(255) not null, `name` varchar(255) not null, `total_jobs` int not null, `pending_jobs` int not null, `failed_jobs` int not null, `failed_job_ids` longtext not null, `options` mediumtext null, `cancelled_at` int null, `created_at` int not null, `finished_at` int null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
create table `failed_jobs` (`id` bigint unsigned not null auto_increment primary key, `uuid` varchar(255) not null, `connection` varchar(255) not null, `queue` varchar(255) not null, `payload` longtext not null, `exception` longtext not null, `failed_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `failed_jobs` add index `failed_jobs_connection_queue_failed_at_index`(`connection`, `queue`, `failed_at`);
alter table `failed_jobs` add unique `failed_jobs_uuid_unique`(`uuid`);
insert into `migrations` (`migration`, `batch`) values ('0001_01_01_000002_create_jobs_table', 1);

-- 0001_01_01_000003_add_two_factor_columns_to_users_table
alter table `users` add `two_factor_secret` text null after `password`;
alter table `users` add `two_factor_recovery_codes` text null after `two_factor_secret`;
alter table `users` add `two_factor_confirmed_at` timestamp null after `two_factor_recovery_codes`;
insert into `migrations` (`migration`, `batch`) values ('0001_01_01_000003_add_two_factor_columns_to_users_table', 1);

-- 0001_01_01_000004_create_personal_access_tokens_table
create table `personal_access_tokens` (`id` bigint unsigned not null auto_increment primary key, `tokenable_type` varchar(255) not null, `tokenable_id` bigint unsigned not null, `name` text not null, `token` varchar(64) not null, `abilities` text null, `last_used_at` timestamp null, `expires_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `personal_access_tokens` add index `personal_access_tokens_tokenable_type_tokenable_id_index`(`tokenable_type`, `tokenable_id`);
alter table `personal_access_tokens` add unique `personal_access_tokens_token_unique`(`token`);
alter table `personal_access_tokens` add index `personal_access_tokens_expires_at_index`(`expires_at`);
insert into `migrations` (`migration`, `batch`) values ('0001_01_01_000004_create_personal_access_tokens_table', 1);

-- 2026_10_28_100000_create_plans_table
create table `plans` (`id` bigint unsigned not null auto_increment primary key, `code` varchar(40) not null, `name` varchar(100) not null, `description` text null, `price_monthly_cents` int unsigned null, `price_yearly_cents` int unsigned null, `currency` char(3) not null default 'EUR', `trial_days` smallint unsigned not null default '0', `limits` json not null, `features` json not null, `is_public` tinyint(1) not null default '1', `is_active` tinyint(1) not null default '1', `sort_order` smallint unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `plans` add unique `plans_code_unique`(`code`);
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100000_create_plans_table', 1);

-- 2026_10_28_100100_create_tenants_table
create table `tenants` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(150) not null, `legal_name` varchar(200) null, `nif` varchar(20) null, `email` varchar(255) null, `phone` varchar(30) null, `website` varchar(255) null, `address_line1` varchar(255) null, `address_line2` varchar(255) null, `postal_code` varchar(20) null, `city` varchar(100) null, `country_code` char(2) not null default 'PT', `logo_path` varchar(255) null, `status` varchar(20) not null default 'active', `status_reason` varchar(255) null, `status_changed_at` timestamp null, `settings` json null, `storage_used_bytes` bigint unsigned not null default '0', `database_connection` varchar(64) null, `created_by_user_id` bigint unsigned null, `deletion_requested_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `tenants` add constraint `tenants_created_by_user_id_foreign` foreign key (`created_by_user_id`) references `users` (`id`) on delete set null;
alter table `tenants` add index `tenants_nif_index`(`nif`);
alter table `tenants` add index `tenants_status_index`(`status`);
alter table `users` add constraint `users_current_tenant_id_foreign` foreign key (`current_tenant_id`) references `tenants` (`id`) on delete set null;
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100100_create_tenants_table', 1);

-- 2026_10_28_100200_create_subscriptions_table
create table `subscriptions` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `plan_id` bigint unsigned not null, `status` varchar(20) not null, `billing_cycle` varchar(10) not null default 'monthly', `trial_ends_at` timestamp null, `current_period_starts_at` timestamp null, `current_period_ends_at` timestamp null, `canceled_at` timestamp null, `ends_at` timestamp null, `custom_limits` json null, `provider` varchar(30) not null default 'manual', `provider_reference` varchar(255) null, `notes` text null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `subscriptions` add constraint `subscriptions_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `subscriptions` add constraint `subscriptions_plan_id_foreign` foreign key (`plan_id`) references `plans` (`id`) on delete restrict;
alter table `subscriptions` add index `subscriptions_tenant_id_status_index`(`tenant_id`, `status`);
alter table `subscriptions` add index `subscriptions_status_trial_ends_at_index`(`status`, `trial_ends_at`);
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100200_create_subscriptions_table', 1);

-- 2026_10_28_100300_create_access_control_tables
create table `permissions` (`id` bigint unsigned not null auto_increment primary key, `key` varchar(100) not null, `module` varchar(50) not null, `description` varchar(255) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `permissions` add unique `permissions_key_unique`(`key`);
alter table `permissions` add index `permissions_module_index`(`module`);
create table `roles` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `key` varchar(60) not null, `name` varchar(100) not null, `description` varchar(255) null, `template_key` varchar(60) null, `is_locked` tinyint(1) not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `roles` add constraint `roles_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `roles` add unique `roles_tenant_id_key_unique`(`tenant_id`, `key`);
alter table `roles` add unique `roles_tenant_id_id_unique`(`tenant_id`, `id`);
create table `permission_role` (`role_id` bigint unsigned not null, `permission_id` bigint unsigned not null, primary key (`role_id`, `permission_id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `permission_role` add constraint `permission_role_role_id_foreign` foreign key (`role_id`) references `roles` (`id`) on delete cascade;
alter table `permission_role` add constraint `permission_role_permission_id_foreign` foreign key (`permission_id`) references `permissions` (`id`) on delete cascade;
create table `memberships` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `user_id` bigint unsigned not null, `role_id` bigint unsigned not null, `status` varchar(20) not null default 'active', `job_title` varchar(100) null, `invited_by_user_id` bigint unsigned null, `joined_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `memberships` add constraint `memberships_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `memberships` add constraint `memberships_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;
alter table `memberships` add constraint `memberships_invited_by_user_id_foreign` foreign key (`invited_by_user_id`) references `users` (`id`) on delete set null;
alter table `memberships` add unique `memberships_tenant_id_user_id_unique`(`tenant_id`, `user_id`);
alter table `memberships` add index `memberships_user_id_status_index`(`user_id`, `status`);
alter table `memberships` add constraint `memberships_tenant_id_role_id_foreign` foreign key (`tenant_id`, `role_id`) references `roles` (`tenant_id`, `id`) on delete cascade;
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100300_create_access_control_tables', 1);

-- 2026_10_28_100400_create_invitations_table
create table `invitations` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `email` varchar(255) not null, `role_id` bigint unsigned not null, `token_hash` char(64) not null, `invited_by_user_id` bigint unsigned null, `expires_at` timestamp not null, `accepted_at` timestamp null, `accepted_by_user_id` bigint unsigned null, `revoked_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `invitations` add constraint `invitations_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `invitations` add constraint `invitations_invited_by_user_id_foreign` foreign key (`invited_by_user_id`) references `users` (`id`) on delete set null;
alter table `invitations` add constraint `invitations_accepted_by_user_id_foreign` foreign key (`accepted_by_user_id`) references `users` (`id`) on delete set null;
alter table `invitations` add index `invitations_tenant_id_email_index`(`tenant_id`, `email`);
alter table `invitations` add constraint `invitations_tenant_id_role_id_foreign` foreign key (`tenant_id`, `role_id`) references `roles` (`tenant_id`, `id`) on delete cascade;
alter table `invitations` add unique `invitations_token_hash_unique`(`token_hash`);
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100400_create_invitations_table', 1);

-- 2026_10_28_100500_create_audit_logs_table
create table `audit_logs` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned null, `actor_user_id` bigint unsigned null, `actor_type` varchar(20) not null, `event` varchar(80) not null, `subject_type` varchar(80) null, `subject_id` bigint unsigned null, `properties` json null, `ip_address` varchar(45) null, `user_agent` varchar(255) null, `request_id` varchar(36) null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `audit_logs` add index `audit_logs_tenant_id_created_at_index`(`tenant_id`, `created_at`);
alter table `audit_logs` add index `audit_logs_subject_type_subject_id_index`(`subject_type`, `subject_id`);
alter table `audit_logs` add index `audit_logs_actor_user_id_index`(`actor_user_id`);
alter table `audit_logs` add index `audit_logs_event_index`(`event`);
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100500_create_audit_logs_table', 1);

-- 2026_10_28_100600_create_support_access_grants_table
create table `support_access_grants` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `granted_by_user_id` bigint unsigned null, `reason` varchar(500) not null, `access_level` varchar(20) not null default 'read', `expires_at` timestamp not null, `revoked_at` timestamp null, `revoked_by_user_id` bigint unsigned null, `last_used_at` timestamp null, `last_used_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `support_access_grants` add constraint `support_access_grants_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `support_access_grants` add constraint `support_access_grants_granted_by_user_id_foreign` foreign key (`granted_by_user_id`) references `users` (`id`) on delete set null;
alter table `support_access_grants` add constraint `support_access_grants_revoked_by_user_id_foreign` foreign key (`revoked_by_user_id`) references `users` (`id`) on delete set null;
alter table `support_access_grants` add constraint `support_access_grants_last_used_by_user_id_foreign` foreign key (`last_used_by_user_id`) references `users` (`id`) on delete set null;
alter table `support_access_grants` add index `support_access_grants_tenant_id_expires_at_index`(`tenant_id`, `expires_at`);
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100600_create_support_access_grants_table', 1);

-- 2026_10_28_100700_create_tenant_exports_table
create table `tenant_exports` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `requested_by_user_id` bigint unsigned null, `status` varchar(20) not null default 'pending', `disk` varchar(30) not null, `path` varchar(255) null, `size_bytes` bigint unsigned null, `checksum_sha256` char(64) null, `error` text null, `completed_at` timestamp null, `expires_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `tenant_exports` add constraint `tenant_exports_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `tenant_exports` add constraint `tenant_exports_requested_by_user_id_foreign` foreign key (`requested_by_user_id`) references `users` (`id`) on delete set null;
alter table `tenant_exports` add index `tenant_exports_tenant_id_created_at_index`(`tenant_id`, `created_at`);
insert into `migrations` (`migration`, `batch`) values ('2026_10_28_100700_create_tenant_exports_table', 1);

-- 2026_11_03_100000_create_projects_tables
alter table `memberships` add unique `memberships_tenant_id_id_unique`(`tenant_id`, `id`);
create table `projects` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `code` varchar(30) not null, `name` varchar(150) not null, `owner_name` varchar(150) null, `type` varchar(30) null, `address` varchar(255) null, `postal_code` varchar(20) null, `city` varchar(100) null, `contract_value_cents` bigint unsigned null, `starts_on` date null, `planned_end_on` date null, `status` varchar(20) not null default 'planning', `description` text null, `created_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `projects` add constraint `projects_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `projects` add constraint `projects_created_by_user_id_foreign` foreign key (`created_by_user_id`) references `users` (`id`) on delete set null;
alter table `projects` add unique `projects_tenant_id_code_unique`(`tenant_id`, `code`);
alter table `projects` add unique `projects_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `projects` add index `projects_tenant_id_status_index`(`tenant_id`, `status`);
create table `project_members` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `project_id` bigint unsigned not null, `membership_id` bigint unsigned not null, `role_id` bigint unsigned not null, `added_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `project_members` add constraint `project_members_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `project_members` add constraint `project_members_added_by_user_id_foreign` foreign key (`added_by_user_id`) references `users` (`id`) on delete set null;
alter table `project_members` add unique `project_members_project_id_membership_id_unique`(`project_id`, `membership_id`);
alter table `project_members` add index `project_members_membership_id_index`(`membership_id`);
alter table `project_members` add constraint `project_members_tenant_id_project_id_foreign` foreign key (`tenant_id`, `project_id`) references `projects` (`tenant_id`, `id`) on delete cascade;
alter table `project_members` add constraint `project_members_tenant_id_membership_id_foreign` foreign key (`tenant_id`, `membership_id`) references `memberships` (`tenant_id`, `id`) on delete cascade;
alter table `project_members` add constraint `project_members_tenant_id_role_id_foreign` foreign key (`tenant_id`, `role_id`) references `roles` (`tenant_id`, `id`) on delete cascade;
insert into `migrations` (`migration`, `batch`) values ('2026_11_03_100000_create_projects_tables', 1);

-- 2026_11_03_100100_create_site_diary_tables
create table `site_diary_entries` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `project_id` bigint unsigned not null, `entry_date` date not null, `status` varchar(20) not null default 'draft', `weather` json null, `work_summary` text null, `notes` text null, `created_by_user_id` bigint unsigned null, `updated_by_user_id` bigint unsigned null, `submitted_at` timestamp null, `submitted_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `site_diary_entries` add constraint `site_diary_entries_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `site_diary_entries` add constraint `site_diary_entries_created_by_user_id_foreign` foreign key (`created_by_user_id`) references `users` (`id`) on delete set null;
alter table `site_diary_entries` add constraint `site_diary_entries_updated_by_user_id_foreign` foreign key (`updated_by_user_id`) references `users` (`id`) on delete set null;
alter table `site_diary_entries` add constraint `site_diary_entries_submitted_by_user_id_foreign` foreign key (`submitted_by_user_id`) references `users` (`id`) on delete set null;
alter table `site_diary_entries` add unique `site_diary_entries_tenant_id_project_id_entry_date_unique`(`tenant_id`, `project_id`, `entry_date`);
alter table `site_diary_entries` add unique `site_diary_entries_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `site_diary_entries` add constraint `site_diary_entries_tenant_id_project_id_foreign` foreign key (`tenant_id`, `project_id`) references `projects` (`tenant_id`, `id`) on delete cascade;
create table `site_diary_items` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `entry_id` bigint unsigned not null, `kind` varchar(20) not null, `description` varchar(255) not null, `quantity` decimal(14, 3) null, `unit` varchar(20) null, `company` varchar(150) null, `sort_order` smallint unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `site_diary_items` add constraint `site_diary_items_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `site_diary_items` add index `site_diary_items_entry_id_kind_index`(`entry_id`, `kind`);
alter table `site_diary_items` add constraint `site_diary_items_tenant_id_entry_id_foreign` foreign key (`tenant_id`, `entry_id`) references `site_diary_entries` (`tenant_id`, `id`) on delete cascade;
insert into `migrations` (`migration`, `batch`) values ('2026_11_03_100100_create_site_diary_tables', 1);

-- 2026_11_04_100000_create_media_table
create table `media` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `project_id` bigint unsigned not null, `diary_entry_id` bigint unsigned null, `kind` varchar(20) not null default 'photo', `status` varchar(20) not null default 'processing', `disk` varchar(30) not null, `original_path` varchar(255) not null, `preview_path` varchar(255) null, `thumb_path` varchar(255) null, `original_name` varchar(255) not null, `mime` varchar(100) not null, `size_bytes` bigint unsigned not null, `derived_bytes` bigint unsigned not null default '0', `sha256` char(64) not null, `width` int unsigned null, `height` int unsigned null, `taken_at` timestamp null, `caption` varchar(255) null, `sort_order` smallint unsigned not null default '0', `uploaded_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `media` add constraint `media_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `media` add constraint `media_uploaded_by_user_id_foreign` foreign key (`uploaded_by_user_id`) references `users` (`id`) on delete set null;
alter table `media` add index `media_tenant_id_project_id_index`(`tenant_id`, `project_id`);
alter table `media` add index `media_diary_entry_id_index`(`diary_entry_id`);
alter table `media` add constraint `media_tenant_id_project_id_foreign` foreign key (`tenant_id`, `project_id`) references `projects` (`tenant_id`, `id`) on delete cascade;
alter table `media` add constraint `media_tenant_id_diary_entry_id_foreign` foreign key (`tenant_id`, `diary_entry_id`) references `site_diary_entries` (`tenant_id`, `id`) on delete cascade;
insert into `migrations` (`migration`, `batch`) values ('2026_11_04_100000_create_media_table', 1);

-- 2026_11_05_100000_create_documents_tables
create table `document_folders` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `project_id` bigint unsigned not null, `parent_id` bigint unsigned null, `name` varchar(150) not null, `created_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `document_folders` add constraint `document_folders_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `document_folders` add constraint `document_folders_created_by_user_id_foreign` foreign key (`created_by_user_id`) references `users` (`id`) on delete set null;
alter table `document_folders` add unique `document_folders_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `document_folders` add index `document_folders_project_id_parent_id_index`(`project_id`, `parent_id`);
alter table `document_folders` add constraint `document_folders_tenant_id_project_id_foreign` foreign key (`tenant_id`, `project_id`) references `projects` (`tenant_id`, `id`) on delete cascade;
alter table `document_folders` add constraint `document_folders_tenant_id_parent_id_foreign` foreign key (`tenant_id`, `parent_id`) references `document_folders` (`tenant_id`, `id`) on delete cascade;
create table `documents` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `project_id` bigint unsigned not null, `folder_id` bigint unsigned null, `title` varchar(200) not null, `description` text null, `created_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `documents` add constraint `documents_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `documents` add constraint `documents_created_by_user_id_foreign` foreign key (`created_by_user_id`) references `users` (`id`) on delete set null;
alter table `documents` add unique `documents_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `documents` add index `documents_project_id_folder_id_index`(`project_id`, `folder_id`);
alter table `documents` add constraint `documents_tenant_id_project_id_foreign` foreign key (`tenant_id`, `project_id`) references `projects` (`tenant_id`, `id`) on delete cascade;
alter table `documents` add constraint `documents_tenant_id_folder_id_foreign` foreign key (`tenant_id`, `folder_id`) references `document_folders` (`tenant_id`, `id`) on delete cascade;
create table `document_versions` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `document_id` bigint unsigned not null, `number` smallint unsigned not null, `disk` varchar(30) not null, `path` varchar(255) not null, `original_name` varchar(255) not null, `mime` varchar(100) not null, `size_bytes` bigint unsigned not null, `sha256` char(64) not null, `note` varchar(255) null, `uploaded_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `document_versions` add constraint `document_versions_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `document_versions` add constraint `document_versions_uploaded_by_user_id_foreign` foreign key (`uploaded_by_user_id`) references `users` (`id`) on delete set null;
alter table `document_versions` add unique `document_versions_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `document_versions` add unique `document_versions_document_id_number_unique`(`document_id`, `number`);
alter table `document_versions` add constraint `document_versions_tenant_id_document_id_foreign` foreign key (`tenant_id`, `document_id`) references `documents` (`tenant_id`, `id`) on delete cascade;
create table `document_downloads` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `document_version_id` bigint unsigned not null, `user_id` bigint unsigned null, `ip_address` varchar(45) null, `created_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `document_downloads` add constraint `document_downloads_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `document_downloads` add constraint `document_downloads_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
alter table `document_downloads` add index `document_downloads_document_version_id_index`(`document_version_id`);
alter table `document_downloads` add constraint `document_downloads_tenant_id_document_version_id_foreign` foreign key (`tenant_id`, `document_version_id`) references `document_versions` (`tenant_id`, `id`) on delete cascade;
insert into `migrations` (`migration`, `batch`) values ('2026_11_05_100000_create_documents_tables', 1);

-- 2026_11_06_100000_create_project_structure_tables
create table `project_locations` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `project_id` bigint unsigned not null, `parent_id` bigint unsigned null, `kind` varchar(20) not null, `name` varchar(100) not null, `sort_order` smallint unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `project_locations` add constraint `project_locations_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `project_locations` add unique `project_locations_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `project_locations` add index `project_locations_project_id_parent_id_index`(`project_id`, `parent_id`);
alter table `project_locations` add constraint `project_locations_tenant_id_project_id_foreign` foreign key (`tenant_id`, `project_id`) references `projects` (`tenant_id`, `id`) on delete cascade;
alter table `project_locations` add constraint `project_locations_tenant_id_parent_id_foreign` foreign key (`tenant_id`, `parent_id`) references `project_locations` (`tenant_id`, `id`) on delete cascade;
create table `construction_elements` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `project_id` bigint unsigned not null, `location_id` bigint unsigned not null, `element_type` varchar(20) not null, `code` varchar(30) not null, `name` varchar(150) null, `notes` text null, `created_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `construction_elements` add constraint `construction_elements_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `construction_elements` add constraint `construction_elements_created_by_user_id_foreign` foreign key (`created_by_user_id`) references `users` (`id`) on delete set null;
alter table `construction_elements` add unique `construction_elements_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `construction_elements` add unique `construction_elements_tenant_id_project_id_code_unique`(`tenant_id`, `project_id`, `code`);
alter table `construction_elements` add index `construction_elements_location_id_index`(`location_id`);
alter table `construction_elements` add constraint `construction_elements_tenant_id_project_id_foreign` foreign key (`tenant_id`, `project_id`) references `projects` (`tenant_id`, `id`) on delete cascade;
alter table `construction_elements` add constraint `construction_elements_tenant_id_location_id_foreign` foreign key (`tenant_id`, `location_id`) references `project_locations` (`tenant_id`, `id`) on delete cascade;
insert into `migrations` (`migration`, `batch`) values ('2026_11_06_100000_create_project_structure_tables', 1);

-- 2026_11_07_100000_link_records_to_structure
alter table `site_diary_items` add `location_id` bigint unsigned null;
alter table `site_diary_items` add `element_id` bigint unsigned null;
alter table `site_diary_items` add index `site_diary_items_location_id_index`(`location_id`);
alter table `site_diary_items` add index `site_diary_items_element_id_index`(`element_id`);
alter table `site_diary_items` add constraint `site_diary_items_location_fk` foreign key (`tenant_id`, `location_id`) references `project_locations` (`tenant_id`, `id`);
alter table `site_diary_items` add constraint `site_diary_items_element_fk` foreign key (`tenant_id`, `element_id`) references `construction_elements` (`tenant_id`, `id`);
alter table `media` add `location_id` bigint unsigned null;
alter table `media` add `element_id` bigint unsigned null;
alter table `media` add index `media_location_id_index`(`location_id`);
alter table `media` add index `media_element_id_index`(`element_id`);
alter table `media` add constraint `media_location_fk` foreign key (`tenant_id`, `location_id`) references `project_locations` (`tenant_id`, `id`);
alter table `media` add constraint `media_element_fk` foreign key (`tenant_id`, `element_id`) references `construction_elements` (`tenant_id`, `id`);
alter table `documents` add `location_id` bigint unsigned null;
alter table `documents` add `element_id` bigint unsigned null;
alter table `documents` add index `documents_location_id_index`(`location_id`);
alter table `documents` add index `documents_element_id_index`(`element_id`);
alter table `documents` add constraint `documents_location_fk` foreign key (`tenant_id`, `location_id`) references `project_locations` (`tenant_id`, `id`);
alter table `documents` add constraint `documents_element_fk` foreign key (`tenant_id`, `element_id`) references `construction_elements` (`tenant_id`, `id`);
insert into `migrations` (`migration`, `batch`) values ('2026_11_07_100000_link_records_to_structure', 1);

-- 2026_11_08_100000_add_client_uuid_for_offline_sync
alter table `site_diary_entries` add `client_uuid` char(36) null;
alter table `site_diary_entries` add unique `site_diary_entries_tenant_id_client_uuid_unique`(`tenant_id`, `client_uuid`);
alter table `media` add `client_uuid` char(36) null;
alter table `media` add unique `media_tenant_id_client_uuid_unique`(`tenant_id`, `client_uuid`);
insert into `migrations` (`migration`, `batch`) values ('2026_11_08_100000_add_client_uuid_for_offline_sync', 1);

-- 2026_11_09_100000_create_clients_and_work_calendars_tables
create table `clients` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `type` varchar(20) not null, `name` varchar(150) not null, `nif` varchar(20) null, `country_code` char(2) not null default 'PT', `email` varchar(255) null, `phone` varchar(30) null, `contact_name` varchar(150) null, `address` varchar(255) null, `postal_code` varchar(20) null, `city` varchar(100) null, `notes` text null, `archived_at` timestamp null, `created_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `clients` add constraint `clients_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `clients` add constraint `clients_created_by_user_id_foreign` foreign key (`created_by_user_id`) references `users` (`id`) on delete set null;
alter table `clients` add unique `clients_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `clients` add unique `clients_tenant_id_nif_unique`(`tenant_id`, `nif`);
alter table `clients` add index `clients_tenant_id_name_index`(`tenant_id`, `name`);
create table `work_calendars` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `name` varchar(100) not null, `weekly_hours` json not null, `is_default` tinyint(1) not null default '0', `created_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `work_calendars` add constraint `work_calendars_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `work_calendars` add constraint `work_calendars_created_by_user_id_foreign` foreign key (`created_by_user_id`) references `users` (`id`) on delete set null;
alter table `work_calendars` add unique `work_calendars_tenant_id_id_unique`(`tenant_id`, `id`);
create table `holidays` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `work_calendar_id` bigint unsigned not null, `date` date not null, `name` varchar(100) not null, `kind` varchar(20) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `holidays` add constraint `holidays_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `holidays` add unique `holidays_work_calendar_id_date_unique`(`work_calendar_id`, `date`);
alter table `holidays` add constraint `holidays_tenant_id_work_calendar_id_foreign` foreign key (`tenant_id`, `work_calendar_id`) references `work_calendars` (`tenant_id`, `id`) on delete cascade;
alter table `projects` add `client_id` bigint unsigned null after `name`;
alter table `projects` add `work_calendar_id` bigint unsigned null after `planned_end_on`;
alter table `projects` add index `projects_client_id_index`(`client_id`);
alter table `projects` add index `projects_work_calendar_id_index`(`work_calendar_id`);
alter table `projects` add constraint `projects_client_fk` foreign key (`tenant_id`, `client_id`) references `clients` (`tenant_id`, `id`);
alter table `projects` add constraint `projects_work_calendar_fk` foreign key (`tenant_id`, `work_calendar_id`) references `work_calendars` (`tenant_id`, `id`);
insert into `migrations` (`migration`, `batch`) values ('2026_11_09_100000_create_clients_and_work_calendars_tables', 1);

-- 2026_11_10_100000_create_departments_and_cost_centers_tables
create table `departments` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `name` varchar(100) not null, `description` varchar(255) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `departments` add constraint `departments_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `departments` add unique `departments_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `departments` add unique `departments_tenant_id_name_unique`(`tenant_id`, `name`);
alter table `memberships` add `department_id` bigint unsigned null after `role_id`;
alter table `memberships` add index `memberships_department_id_index`(`department_id`);
alter table `memberships` add constraint `memberships_department_fk` foreign key (`tenant_id`, `department_id`) references `departments` (`tenant_id`, `id`);
create table `cost_centers` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `parent_id` bigint unsigned null, `code` varchar(30) not null, `name` varchar(150) not null, `archived_at` timestamp null, `created_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `cost_centers` add constraint `cost_centers_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `cost_centers` add constraint `cost_centers_created_by_user_id_foreign` foreign key (`created_by_user_id`) references `users` (`id`) on delete set null;
alter table `cost_centers` add unique `cost_centers_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `cost_centers` add unique `cost_centers_tenant_id_code_unique`(`tenant_id`, `code`);
alter table `cost_centers` add index `cost_centers_parent_id_index`(`parent_id`);
alter table `cost_centers` add constraint `cost_centers_tenant_id_parent_id_foreign` foreign key (`tenant_id`, `parent_id`) references `cost_centers` (`tenant_id`, `id`);
alter table `projects` add `cost_center_id` bigint unsigned null after `work_calendar_id`;
alter table `projects` add index `projects_cost_center_id_index`(`cost_center_id`);
alter table `projects` add constraint `projects_cost_center_fk` foreign key (`tenant_id`, `cost_center_id`) references `cost_centers` (`tenant_id`, `id`);
insert into `migrations` (`migration`, `batch`) values ('2026_11_10_100000_create_departments_and_cost_centers_tables', 1);

-- 2026_11_11_100000_create_specialties_table
create table `specialties` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `code` varchar(10) not null, `name` varchar(100) not null, `sort_order` smallint unsigned not null default '0', `archived_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `specialties` add constraint `specialties_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `specialties` add unique `specialties_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `specialties` add unique `specialties_tenant_id_code_unique`(`tenant_id`, `code`);
alter table `construction_elements` add `specialty_id` bigint unsigned null;
alter table `construction_elements` add index `construction_elements_specialty_id_index`(`specialty_id`);
alter table `construction_elements` add constraint `construction_elements_specialty_fk` foreign key (`tenant_id`, `specialty_id`) references `specialties` (`tenant_id`, `id`);
alter table `documents` add `specialty_id` bigint unsigned null;
alter table `documents` add index `documents_specialty_id_index`(`specialty_id`);
alter table `documents` add constraint `documents_specialty_fk` foreign key (`tenant_id`, `specialty_id`) references `specialties` (`tenant_id`, `id`);
insert into `migrations` (`migration`, `batch`) values ('2026_11_11_100000_create_specialties_table', 1);

-- 2026_11_12_100000_create_inspection_tables
create table `checklist_templates` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `name` varchar(150) not null, `description` text null, `specialty_id` bigint unsigned null, `version` int unsigned not null default '1', `archived_at` timestamp null, `created_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `checklist_templates` add constraint `checklist_templates_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `checklist_templates` add constraint `checklist_templates_created_by_user_id_foreign` foreign key (`created_by_user_id`) references `users` (`id`) on delete set null;
alter table `checklist_templates` add unique `checklist_templates_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `checklist_templates` add unique `checklist_templates_tenant_id_name_unique`(`tenant_id`, `name`);
alter table `checklist_templates` add index `checklist_templates_specialty_id_index`(`specialty_id`);
alter table `checklist_templates` add constraint `checklist_templates_specialty_fk` foreign key (`tenant_id`, `specialty_id`) references `specialties` (`tenant_id`, `id`);
create table `checklist_template_items` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `template_id` bigint unsigned not null, `sort_order` smallint unsigned not null default '0', `text` varchar(500) not null, `guidance` varchar(500) null, `requires_photo` tinyint(1) not null default '0', `min_photos` tinyint unsigned not null default '0', `requires_measure` tinyint(1) not null default '0', `measure_unit` varchar(20) null, `measure_min` decimal(14, 4) null, `measure_max` decimal(14, 4) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `checklist_template_items` add constraint `checklist_template_items_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `checklist_template_items` add index `checklist_template_items_template_id_index`(`template_id`);
alter table `checklist_template_items` add constraint `checklist_template_items_tenant_id_template_id_foreign` foreign key (`tenant_id`, `template_id`) references `checklist_templates` (`tenant_id`, `id`) on delete cascade;
create table `inspections` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `project_id` bigint unsigned not null, `number` int unsigned not null, `title` varchar(200) not null, `description` text null, `checklist_template_id` bigint unsigned null, `template_version` int unsigned null, `reinspection_of_id` bigint unsigned null, `location_id` bigint unsigned null, `element_id` bigint unsigned null, `specialty_id` bigint unsigned null, `status` varchar(30) not null default 'draft', `remote` tinyint(1) not null default '0', `scheduled_for` datetime null, `requested_by_user_id` bigint unsigned null, `requested_at` timestamp null, `inspector_user_id` bigint unsigned null, `started_at` timestamp null, `submitted_at` timestamp null, `decided_by_user_id` bigint unsigned null, `decided_at` timestamp null, `decision_note` text null, `evidence_score` tinyint unsigned null, `created_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `inspections` add constraint `inspections_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `inspections` add constraint `inspections_requested_by_user_id_foreign` foreign key (`requested_by_user_id`) references `users` (`id`) on delete set null;
alter table `inspections` add constraint `inspections_inspector_user_id_foreign` foreign key (`inspector_user_id`) references `users` (`id`) on delete set null;
alter table `inspections` add constraint `inspections_decided_by_user_id_foreign` foreign key (`decided_by_user_id`) references `users` (`id`) on delete set null;
alter table `inspections` add constraint `inspections_created_by_user_id_foreign` foreign key (`created_by_user_id`) references `users` (`id`) on delete set null;
alter table `inspections` add unique `inspections_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `inspections` add unique `inspections_tenant_id_project_id_number_unique`(`tenant_id`, `project_id`, `number`);
alter table `inspections` add index `inspections_tenant_id_project_id_status_index`(`tenant_id`, `project_id`, `status`);
alter table `inspections` add index `inspections_checklist_template_id_index`(`checklist_template_id`);
alter table `inspections` add index `inspections_reinspection_of_id_index`(`reinspection_of_id`);
alter table `inspections` add index `inspections_location_id_index`(`location_id`);
alter table `inspections` add index `inspections_element_id_index`(`element_id`);
alter table `inspections` add index `inspections_specialty_id_index`(`specialty_id`);
alter table `inspections` add constraint `inspections_tenant_id_project_id_foreign` foreign key (`tenant_id`, `project_id`) references `projects` (`tenant_id`, `id`) on delete cascade;
alter table `inspections` add constraint `inspections_template_fk` foreign key (`tenant_id`, `checklist_template_id`) references `checklist_templates` (`tenant_id`, `id`);
alter table `inspections` add constraint `inspections_reinspection_fk` foreign key (`tenant_id`, `reinspection_of_id`) references `inspections` (`tenant_id`, `id`);
alter table `inspections` add constraint `inspections_location_fk` foreign key (`tenant_id`, `location_id`) references `project_locations` (`tenant_id`, `id`);
alter table `inspections` add constraint `inspections_element_fk` foreign key (`tenant_id`, `element_id`) references `construction_elements` (`tenant_id`, `id`);
alter table `inspections` add constraint `inspections_specialty_fk` foreign key (`tenant_id`, `specialty_id`) references `specialties` (`tenant_id`, `id`);
create table `inspection_items` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `inspection_id` bigint unsigned not null, `sort_order` smallint unsigned not null default '0', `text` varchar(500) not null, `guidance` varchar(500) null, `requires_photo` tinyint(1) not null default '0', `min_photos` tinyint unsigned not null default '0', `requires_measure` tinyint(1) not null default '0', `measure_unit` varchar(20) null, `measure_min` decimal(14, 4) null, `measure_max` decimal(14, 4) null, `result` varchar(10) null, `measure_value` decimal(14, 4) null, `notes` text null, `answered_by_user_id` bigint unsigned null, `answered_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `inspection_items` add constraint `inspection_items_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `inspection_items` add constraint `inspection_items_answered_by_user_id_foreign` foreign key (`answered_by_user_id`) references `users` (`id`) on delete set null;
alter table `inspection_items` add unique `inspection_items_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `inspection_items` add index `inspection_items_inspection_id_index`(`inspection_id`);
alter table `inspection_items` add constraint `inspection_items_tenant_id_inspection_id_foreign` foreign key (`tenant_id`, `inspection_id`) references `inspections` (`tenant_id`, `id`) on delete cascade;
create table `inspection_events` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `inspection_id` bigint unsigned not null, `sequence` int unsigned not null, `action` varchar(40) not null, `from_status` varchar(30) null, `to_status` varchar(30) not null, `user_id` bigint unsigned null, `user_name` varchar(150) not null, `reason` text null, `payload` longtext null, `prev_hash` char(64) not null, `hash` char(64) not null, `created_at` timestamp not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `inspection_events` add constraint `inspection_events_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `inspection_events` add unique `inspection_events_inspection_id_sequence_unique`(`inspection_id`, `sequence`);
alter table `inspection_events` add constraint `inspection_events_tenant_id_inspection_id_foreign` foreign key (`tenant_id`, `inspection_id`) references `inspections` (`tenant_id`, `id`) on delete cascade;
alter table `media` add `inspection_item_id` bigint unsigned null;
alter table `media` add index `media_inspection_item_id_index`(`inspection_item_id`);
alter table `media` add constraint `media_inspection_item_fk` foreign key (`tenant_id`, `inspection_item_id`) references `inspection_items` (`tenant_id`, `id`) on delete cascade;
insert into `migrations` (`migration`, `batch`) values ('2026_11_12_100000_create_inspection_tables', 1);

-- 2026_11_13_100000_create_hold_points_and_issues
alter table `checklist_templates` add `hold_point` tinyint(1) not null default '0';
alter table `inspections` add `hold_point` tinyint(1) not null default '0';
alter table `inspections` add `hold_released_at` timestamp null;
alter table `inspections` add `hold_released_by_user_id` bigint unsigned null;
alter table `inspections` add constraint `inspections_hold_released_by_user_id_foreign` foreign key (`hold_released_by_user_id`) references `users` (`id`) on delete set null;
create table `issues` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `project_id` bigint unsigned not null, `number` int unsigned not null, `kind` varchar(20) not null, `severity` varchar(20) not null, `status` varchar(20) not null default 'open', `title` varchar(200) not null, `description` text null, `location_id` bigint unsigned null, `element_id` bigint unsigned null, `specialty_id` bigint unsigned null, `inspection_id` bigint unsigned null, `inspection_item_id` bigint unsigned null, `assignee_user_id` bigint unsigned null, `due_on` date null, `escalated_at` timestamp null, `resolution_note` text null, `resolved_by_user_id` bigint unsigned null, `resolved_at` timestamp null, `closed_by_user_id` bigint unsigned null, `closed_at` timestamp null, `created_by_user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `issues` add constraint `issues_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `issues` add constraint `issues_assignee_user_id_foreign` foreign key (`assignee_user_id`) references `users` (`id`) on delete set null;
alter table `issues` add constraint `issues_resolved_by_user_id_foreign` foreign key (`resolved_by_user_id`) references `users` (`id`) on delete set null;
alter table `issues` add constraint `issues_closed_by_user_id_foreign` foreign key (`closed_by_user_id`) references `users` (`id`) on delete set null;
alter table `issues` add constraint `issues_created_by_user_id_foreign` foreign key (`created_by_user_id`) references `users` (`id`) on delete set null;
alter table `issues` add unique `issues_tenant_id_id_unique`(`tenant_id`, `id`);
alter table `issues` add unique `issues_tenant_id_project_id_number_unique`(`tenant_id`, `project_id`, `number`);
alter table `issues` add index `issues_tenant_id_project_id_status_index`(`tenant_id`, `project_id`, `status`);
alter table `issues` add index `issues_status_due_on_index`(`status`, `due_on`);
alter table `issues` add index `issues_location_id_index`(`location_id`);
alter table `issues` add index `issues_element_id_index`(`element_id`);
alter table `issues` add index `issues_specialty_id_index`(`specialty_id`);
alter table `issues` add index `issues_inspection_id_index`(`inspection_id`);
alter table `issues` add index `issues_inspection_item_id_index`(`inspection_item_id`);
alter table `issues` add constraint `issues_tenant_id_project_id_foreign` foreign key (`tenant_id`, `project_id`) references `projects` (`tenant_id`, `id`) on delete cascade;
alter table `issues` add constraint `issues_location_fk` foreign key (`tenant_id`, `location_id`) references `project_locations` (`tenant_id`, `id`);
alter table `issues` add constraint `issues_element_fk` foreign key (`tenant_id`, `element_id`) references `construction_elements` (`tenant_id`, `id`);
alter table `issues` add constraint `issues_specialty_fk` foreign key (`tenant_id`, `specialty_id`) references `specialties` (`tenant_id`, `id`);
alter table `issues` add constraint `issues_inspection_fk` foreign key (`tenant_id`, `inspection_id`) references `inspections` (`tenant_id`, `id`);
alter table `issues` add constraint `issues_inspection_item_fk` foreign key (`tenant_id`, `inspection_item_id`) references `inspection_items` (`tenant_id`, `id`);
create table `issue_events` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `issue_id` bigint unsigned not null, `type` varchar(30) not null, `user_id` bigint unsigned null, `user_name` varchar(150) null, `body` text null, `payload` text null, `created_at` timestamp not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `issue_events` add constraint `issue_events_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `issue_events` add index `issue_events_issue_id_index`(`issue_id`);
alter table `issue_events` add constraint `issue_events_tenant_id_issue_id_foreign` foreign key (`tenant_id`, `issue_id`) references `issues` (`tenant_id`, `id`) on delete cascade;
alter table `media` add `issue_id` bigint unsigned null;
alter table `media` add index `media_issue_id_index`(`issue_id`);
alter table `media` add constraint `media_issue_fk` foreign key (`tenant_id`, `issue_id`) references `issues` (`tenant_id`, `id`) on delete cascade;
insert into `migrations` (`migration`, `batch`) values ('2026_11_13_100000_create_hold_points_and_issues', 1);

-- Dados base
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('company.view', 'company', 'Ver dados da empresa', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('company.update', 'company', 'Editar dados e logótipo da empresa', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('security.manage', 'company', 'Definir políticas de segurança (ex.: 2FA obrigatório)', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('members.view', 'members', 'Ver utilizadores e convites', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('members.invite', 'members', 'Convidar utilizadores', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('members.manage', 'members', 'Alterar perfil, suspender e remover utilizadores', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('roles.view', 'roles', 'Ver perfis e permissões', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('roles.manage', 'roles', 'Criar e editar perfis', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('audit.view', 'audit', 'Consultar o registo de auditoria', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('subscription.view', 'subscription', 'Ver plano, limites e utilização', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('support.manage', 'support', 'Autorizar e revogar acesso do suporte técnico', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('data.export', 'data', 'Exportar todos os dados da empresa', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('departments.manage', 'organization', 'Gerir departamentos e as pessoas de cada um', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('cost_centers.manage', 'organization', 'Gerir centros de custo', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('specialties.manage', 'organization', 'Gerir especialidades (Arquitetura, Estruturas, AVAC...)', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('clients.view', 'clients', 'Ver clientes e os seus contactos', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('clients.manage', 'clients', 'Criar, editar, arquivar e apagar clientes', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('calendars.manage', 'calendars', 'Gerir calendários de trabalho, feriados e paragens', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('projects.all', 'projects', 'Acesso a todas as obras (sem esta permissão, só às obras de cuja equipa faz parte)', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('projects.view', 'projects', 'Ver obras', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('projects.create', 'projects', 'Criar obras', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('projects.update', 'projects', 'Editar os dados da obra', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('projects.team', 'projects', 'Gerir a equipa da obra', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('projects.archive', 'projects', 'Concluir, arquivar e reabrir obras', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('structure.manage', 'structure', 'Gerir edifícios, pisos, zonas e elementos construtivos', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('diary.view', 'diary', 'Consultar o diário de obra', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('diary.write', 'diary', 'Escrever registos do diário (rascunho)', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('diary.submit', 'diary', 'Submeter registos do diário (deixam de poder ser alterados)', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('documents.view', 'documents', 'Consultar e descarregar documentos', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('documents.upload', 'documents', 'Enviar documentos (e novas versões dos que enviou)', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('documents.manage', 'documents', 'Gerir pastas, mover e apagar documentos, novas versões de qualquer documento', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('checklists.manage', 'checklists', 'Gerir os modelos de listas de verificação da empresa', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('inspections.request', 'inspections', 'Preparar e pedir inspeções, pedir reinspeções e cancelar as que ainda não foram decididas', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('inspections.perform', 'inspections', 'Agendar e fazer inspeções: verificar os pontos e juntar evidências', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('inspections.approve', 'inspections', 'Decidir inspeções: aprovar, aprovar com reservas, rejeitar ou devolver para completar', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('inspections.release_hold_point', 'inspections', 'Libertar pontos de paragem (depois de a inspeção ser aprovada)', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('issues.view', 'issues', 'Consultar ocorrências e não conformidades', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('issues.create', 'issues', 'Registar ocorrências e não conformidades, comentar e juntar fotografias', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('issues.resolve', 'issues', 'Resolver as ocorrências de que é responsável', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `permissions` (`key`, `module`, `description`, `updated_at`, `created_at`) values ('issues.validate', 'issues', 'Validar e fechar, reabrir e anular ocorrências; mudar responsável e prazo de qualquer uma', '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `plans` (`code`, `name`, `description`, `price_monthly_cents`, `price_yearly_cents`, `trial_days`, `limits`, `features`, `sort_order`, `currency`, `is_public`, `is_active`, `updated_at`, `created_at`) values ('start', 'Start', 'Para pequenas empresas: até 3 obras ativas e funcionalidades essenciais.', 4900, 49000, 30, '{\"max_users\":5,\"max_active_projects\":3,\"storage_mb\":5120}', '[\"diary\",\"documents\"]', 10, 'EUR', 1, 1, '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `plans` (`code`, `name`, `description`, `price_monthly_cents`, `price_yearly_cents`, `trial_days`, `limits`, `features`, `sort_order`, `currency`, `is_public`, `is_active`, `updated_at`, `created_at`) values ('professional', 'Professional', 'Até 15 obras ativas, fiscalização, controlo de custos e relatórios avançados.', 12900, 129000, 30, '{\"max_users\":25,\"max_active_projects\":15,\"storage_mb\":51200}', '[\"diary\",\"documents\",\"inspections\",\"costs\",\"advanced_reports\"]', 20, 'EUR', 1, 1, '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `plans` (`code`, `name`, `description`, `price_monthly_cents`, `price_yearly_cents`, `trial_days`, `limits`, `features`, `sort_order`, `currency`, `is_public`, `is_active`, `updated_at`, `created_at`) values ('business', 'Business', 'Mais obras, inteligência artificial, integrações e automações.', 29900, 299000, 30, '{\"max_users\":100,\"max_active_projects\":60,\"storage_mb\":204800}', '[\"diary\",\"documents\",\"inspections\",\"costs\",\"advanced_reports\",\"ai\",\"integrations\",\"automations\"]', 30, 'EUR', 1, 1, '2026-11-06 11:28:29', '2026-11-06 11:28:29');
insert into `plans` (`code`, `name`, `description`, `price_monthly_cents`, `price_yearly_cents`, `trial_days`, `limits`, `features`, `sort_order`, `currency`, `is_public`, `is_active`, `updated_at`, `created_at`) values ('enterprise', 'Enterprise', 'Preço personalizado, integrações dedicadas, suporte prioritário e requisitos específicos de segurança.', null, null, 0, '{\"max_users\":null,\"max_active_projects\":null,\"storage_mb\":null}', '[\"diary\",\"documents\",\"inspections\",\"costs\",\"advanced_reports\",\"ai\",\"integrations\",\"automations\",\"dedicated_database\"]', 40, 'EUR', 1, 1, '2026-11-06 11:28:29', '2026-11-06 11:28:29');

SET FOREIGN_KEY_CHECKS = 1;
