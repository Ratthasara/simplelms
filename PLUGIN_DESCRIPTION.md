## Simple LMS 3.5.0

Simple LMS is the complete, standalone academic platform. It owns the entire LMS dashboard, including programs, terms, subjects, students, staff, imports, exports, enrollments, teaching workspaces, attendance, assessments, gradebooks, records, ID cards, notifications, and administration workflows.

Academic Management is rendered and processed directly by Simple LMS. It does not depend on Simple Editors and does not delegate dashboard sections through filters or an integration API.

### Upgrade and data safety

- Database schema remains version `14`.
- Existing LMS table names, post types, taxonomies, user-profile records, enrollments, term IDs, subject IDs, and metadata keys remain unchanged.
- The upgrade performs no student, staff, subject, term, program, enrollment, attendance, grade, or assessment deletion.
- Existing import templates are included in the LMS package.
- The WordPress `editor` role is separated from LMS authorization; Simple Editors may use that role for public website content without taking ownership of any LMS dashboard feature.

Simple Editors is optional and independent. When installed, it manages only news, site announcements, journal articles, thesis entries, admission status, and its own frontend content workspace.

### 3.5.0 auditor interface update

- Displays a smaller Auditor tag immediately after the auditor's name in subject rosters.
- Displays the same inline tag after auditor names in assignment submission cards and discussion threads/replies.
- Uses the same expanded fact-card design as student roster cards, with three auditor detail cards.
- Does not alter audit enrollments, users, subjects, submissions, discussions, grades, or database schema.
