# Simple LMS data-safety contract

Simple LMS upgrades use versioned migrations and preserve existing academic records. Version 3.5.3 uses schema version `15` for the additive Email Center storage described below.

It does not rename, recreate, truncate, or drop:

- WordPress users used by students and staff
- the LMS user profile table
- enrollment or staff-auditor relationships
- term records
- `slms_program` taxonomy terms
- `slms_subject` posts or subject metadata
- attendance, assessment, gradebook, submission, discussion, notification, broadcast, audit, or ID-card records

Activation and upgrade reconcile LMS role capabilities and register existing content types, hooks, and scheduled events. Data-removal functions run only after an authorized administrator explicitly submits the relevant deletion action.

## Version 3.5.0 interface-only change

The 3.5.0 update changes only frontend auditor-label placement and auditor roster-card presentation. It does not rename, migrate, delete, or recalculate existing students, staff, subjects, audit enrollments, assignment submissions, discussion posts/replies, attendance, grades, or results.


## Version 3.5.2 person-ID generation fix

Version 3.5.2 changes only automatic ID generation for newly created people. It does not rewrite existing `person_code` values, WordPress usernames, user IDs, ID-card snapshots, enrollments, grades, attendance, audit logs, or any other record.

New automatically generated IDs use the existing institution/type configuration and the format `UGPS-YYXYZ` for students or `UGPF-YYXYZ` for staff under the default UGP settings. `YY` is calculated at registration time in the configured academic timezone, and `XYZ` is a unique randomized three-digit combination checked against both existing profile codes and WordPress usernames.

`SLMS_DB_VERSION` remains `14`. The existing `person_code_counters` table is retained unchanged for backward/downgrade compatibility, but version 3.5.2 no longer uses it to generate new IDs.

## Version 3.5.3 Email Center

Version 3.5.3 raises the database schema version to `15` using an additive migration. It creates the `slms_email_campaigns` table and adds a nullable, indexed `campaign_id` column plus a nullable `processing_started_at` queue-claim timestamp to `slms_notifications`.

The migration does not update, rename, recreate, or delete existing users, profiles, person codes, broadcasts, notifications, enrollments, subjects, grades, attendance, ID cards, or audit records. Existing notification rows retain a null campaign relationship.

## Version 3.5.13 discussion ordering

Version 3.5.13 changes only the display order of discussion threads within a subject. Threads are shown chronologically from oldest to newest, with the post ID used as a deterministic tie-breaker for matching publication times. It does not modify discussion posts, replies, publication dates, metadata, or database schema.

## Version 3.5.14 discussion start-date ordering fix

Version 3.5.14 enforces chronological discussion ordering at the final subject-item rendering stage. Threads are compared using their original WordPress `post_date`, from oldest to newest, with the post ID as a deterministic tie-breaker. Welcome threads follow the same date order as every other thread. This release does not update discussion content, replies, dates, metadata, or database schema.
