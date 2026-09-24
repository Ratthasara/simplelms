=== Simple LMS ===
Contributors: venratthasara
Tags: lms, university, education, gradebook, attendance
Requires at least: 6.4
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 3.5.16
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

University-first learning management system for academic operations, teaching delivery, assessments, attendance, gradebooks, people workflows, and long-term records oversight.

== Description ==

Simple LMS is designed for universities that need one dashboard-first platform for teaching and academic administration.

Key areas included in this release:

* unified dashboard entry point for admins, officers, lecturers, staff, and students
* academic core with programs, subjects, terms, lecturer assignment, and enrollments
* people workflow and people directory for student and staff onboarding
* learning delivery with lessons and announcements tied to subjects
* assessment center with assignment submission and grading workflows
* weighted gradebook with assignment-linked scoring and manual components
* visual attendance board with numbered class meetings, portrait-based present/absent marking, compact Late/Leave exception controls, proportional attendance scoring, and attendance review tools
* records hub for historical browsing of rosters, attendance, and grade outcomes across years and terms
* audit logging, notifications, and optional REST endpoints for core operations
* self-contained Academic Management for programs, terms, subjects, students, staff, imports, exports, and account workflows
* optional compatibility with the separate Simple Editors plugin for public website content only

== Installation ==

1. Upload `Simple LMS.zip` through `Plugins > Add New > Upload Plugin`.
2. Activate the plugin.
3. Visit `Simple LMS` in the WordPress admin menu.
4. Configure institution settings in `Simple LMS > Settings`.
5. Create terms, programs, subjects, and sections before onboarding students and staff.
6. Simple LMS is fully functional on its own. Activate Simple Editors only when a separate frontend website-content workspace is required.

== Frequently Asked Questions ==

= Does uninstall remove university records? =

No. The included uninstall routine only clears scheduled cron events. Academic data remains in place unless a custom data-removal routine is introduced later.

= Can this run alongside the legacy plugins during transition? =

Yes, but production replacement should be planned carefully. Review roles, overlapping menus, and any migration decisions before enabling it on a live university website.

== Changelog ==

= 3.5.16 =
* Interface: Display numbered subject weeks in reverse order so the latest week appears first in Materials and Assignments.
* Interface: Keep General items without a week assignment at the bottom of grouped subject lists.
* Interface: Display discussion threads from newest to oldest while preserving oldest-to-newest reply chronology inside each thread.
* Compatibility: Preserve lecturer-defined Order In Week values and continue using newest-first publication date and post ID fallbacks for unordered items.
* Data safety: Keep database schema version 16 unchanged; this release only changes subject-item presentation order.

= 3.5.15 =
* Fix: Recalculate assignment lateness from the latest submission time and the assignment's current deadline, so extending or shortening a deadline immediately updates late status and penalties.
* Compatibility: Separate submission workflow state from derived lateness and normalize legacy late rows to submitted or resubmitted while preserving their identifiers and timestamps.
* Timezone: Use an LMS-owned Asia/Yangon clock for academic storage, parsing, comparisons, displays, notifications, and generated filenames without changing the global WordPress site timezone.
* Reliability: Correct deadline comparisons to use true Unix timestamps and consistently interpret stored LMS DATETIME values as Yangon wall-clock time.
* Data safety: Upgrade database schema metadata to version 16, retain a list of converted legacy submission IDs, and leave scores, deadlines, submission timestamps, content, and attachments unchanged.

= 3.5.14 =
* Fix: Preserve oldest-to-newest discussion ordering through the final subject-item rendering stage.
* Reliability: Sort threads by their original WordPress start date and use the post ID as a deterministic tie-breaker for matching dates.
* Interface: Remove welcome-thread pinning so every discussion follows the same strict chronological order.
* Data safety: Keep database schema version 15 unchanged; this release does not modify discussion content, dates, replies, or metadata.

= 3.5.13 =
* Interface: Order subject discussion threads chronologically from oldest at the top to newest at the bottom.
* Reliability: Use the post ID as a deterministic tie-breaker when discussion threads share the same publication time.
* Data safety: Keep database schema version 15 unchanged; this release only changes discussion display ordering.

= 3.5.12 =
* Interface: Change the multi-embed button label to "Embed another video/audio" in the portal and discussion editor.
* Data safety: Keep database schema version 15 unchanged; this release changes interface wording only.

= 3.5.11 =
* Feature: Allow lecturers to add, remove, and publish up to 10 ordered embedded video or audio links on subject materials, assignments, and discussion topics.
* Interface: Display multiple embedded players as separate responsive cards with comfortable spacing.
* Accessibility: Show the complete original media URL beneath every embedded player so students can open it in a full browser.
* Compatibility: Preserve the first URL in the existing single-embed metadata and automatically use existing single URLs when no multi-embed metadata exists.
* Data safety: Keep database schema version 15 unchanged and require no migration of existing materials, assignments, or discussions.

= 3.5.10 =
* Interface: Bring dashboard card descriptions closer to their dashicons and title stacks by tightening desktop-only spacing and balancing the space above and below descriptions on cards without code/stat labels.
* Interface: Preserve equal first-layout card heights, centered title/icon alignment, aligned description rows, and natural mobile card spacing.
* Data safety: Keep database schema version 15 unchanged; this release modifies dashboard presentation only.

= 3.5.9 =
* Feature: Add a Staff role to staff creation, editing, filtering, imports, ID card filters, announcements, broadcasts, and email audiences.
* Security: Give Staff only WordPress read access and LMS notification receipt, with no academic management, teaching, grading, attendance, communication publishing, or content-editing capabilities.
* Security: Restrict administrator role assignment and administrator-account changes or deletion to site administrators.
* Compatibility: Accept both staff and slms_staff as import role values while using the collision-resistant slms_staff WordPress role key internally.
* Data safety: Keep database schema version 15 unchanged and preserve all existing users, roles, profiles, and academic records.

= 3.5.8 =
* Interface: Normalize desktop dashboard card heights during the browser's initial CSS Grid layout, eliminating the uneven pre-layout flash.
* Performance: Remove JavaScript height measurement and its dependency-loading delay from dashboard card sizing.
* Interface: Isolate populated Upcoming Assignments content from intrinsic row sizing while retaining its internal scroll area.
* Data safety: Keep database schema version 15 unchanged; this release modifies dashboard presentation only.

= 3.5.7 =
* Interface: Prevent the desktop dashboard from briefly displaying uneven card heights while runtime height normalization is in progress.
* Reliability: Add a timed CSS visibility fallback so dashboard cards remain available if JavaScript cannot complete the layout pass.
* Data safety: Keep database schema version 15 unchanged; this release modifies dashboard presentation only.

= 3.5.6 =
* Interface: Vertically center dashboard card titles against their dashicons while preserving aligned descriptions and responsive card heights.
* Data safety: Keep database schema version 15 unchanged; this release modifies dashboard presentation only.

= 3.5.5 =
* Interface: Rebase the dashboard layout on version 3.5.3 and normalize desktop cards to the tallest natural dashboard card at runtime.
* Interface: Align icons, headings, description text, code/statistic labels, and bottom metadata to consistent desktop card rows.
* Interface: Keep mobile dashboard cards at their natural height for the existing single-column responsive layout.
* Interface: Use a single-card width for Upcoming Assignments and keep populated assignment lists scrollable without allowing them to inflate every dashboard card.
* Data safety: Keep database schema version 15 unchanged; this release modifies dashboard presentation only.

= 3.5.3 =
* Feature: Add an administrator-only Email Center for email-only announcements to all staff, all students, or selected class groups.
* Delivery: Queue one private email per deduplicated recipient through the existing Simple LMS email and SMTP infrastructure.
* Operations: Add campaign delivery totals, test emails, cancellation of pending deliveries, and retry controls for failed deliveries.
* Architecture: Share recipient-group resolution with Broadcast Center while keeping email campaigns fully separate from in-app broadcasts and acknowledgement records.
* Reliability: Atomically claim queued emails so overlapping cron workers cannot send the same delivery twice, with recovery for abandoned claims.
* Data safety: Add only the email campaign table and nullable notification queue fields; no existing LMS records are modified or removed.

= 3.5.2 =
* Fix: Restore auto-generated student IDs to `UGPS-YYXYZ` and staff IDs to `UGPF-YYXYZ`, using a unique randomized three-digit yearly suffix.
* Data safety: Preserve every existing student/staff ID and username; the fix applies only when a new ID is generated automatically.
* Compatibility: Keep database schema version 14 and retain the legacy person-code counter table without using or deleting it.

= 3.5.1 =
* Fix: Keep the Broadcast Center popup close button anchored to the top-right corner.

= 3.5.0 =
* Restored complete ownership of Academic Management to Simple LMS.
* Restored built-in frontend rendering and processing for programs, terms, subjects, students, staff, imports, exports, and enrollment-related account workflows.
* Removed the Simple Editors integration API, academic renderer filter, and public website publishing ownership from LMS core.
* Restored the student, staff, and subject CSV/XLSX import templates to the LMS package.
* Limited LMS roles to Administrator, Officer, Lecturer, and Student; the WordPress Editor role is released from LMS capabilities for content-only use.
* Kept database schema version 14 and every existing LMS table, post type, taxonomy, option, user profile, enrollment, and subject identifier unchanged.
* Added no destructive data migration and no automatic deletion, recreation, or renaming of students, staff, subjects, terms, programs, or academic records.

= 3.4.20 =
* Fix: Allow students and staff auditors to download only their own assignment submission while preserving assignment-grader access.
* Fix: Replace nested submission-download forms with nonce-protected links so the student submission form remains valid HTML.
* Data integrity: Include staff audit enrollments in subject and staff deletion guardrails and force-cleanup operations.
* Compatibility: Preserve the existing lecturer role's standard post editing and publishing capabilities during capability reconciliation.
* Compatibility: Declare nullable ID-card constructor and renderer parameters explicitly for PHP 8.4 and newer.
* Compatibility: Keep the version 14 database schema unchanged; this release does not rename, recreate, or drop LMS tables.

= 3.4.19 =
* Compatibility: Preserved the version 14 database schema and restored the established Asia/Yangon academic timezone policy.
* Architecture: Added a versioned integration API for Simple Editors and moved publishing content-type ownership into core.
* Authorization: Added dedicated capabilities for announcements, journals, and theses; reconciled managed role capabilities on upgrade.
* Data integrity: Centralized subject, student, and staff administration operations in core with checked transactions and audit events.
* Security: Added canonical upload path containment and verified Apache/IIS protection-file writes for managed private uploads.
* Onboarding: Made password setup email queuing mandatory for accounts blocked pending password setup.
* Lifecycle: Added public publishing rewrite registration on activation and cleared automatic term cron during uninstall.



= 3.4.18 =
* Security: Removed the shared temporary password provisioning flow and changed welcome notifications to password setup links.
* Security: Enforced pending password setup during authentication.
* Security: Restricted administrator account creation and assignment to site administrators.
* Security: Restricted remote imports to Google Sheets spreadsheet CSV export URLs or uploaded CSV/XLSX files.
* Security: Honored the REST API settings toggle before registering Simple LMS REST controllers.
* Security: Replaced visible submission attachment URLs with permission-checked download actions in portal and admin screens.
* Hardening: Redacted SMTP passwords from settings audit log context.
* Improvement: Switched person-code generation to the existing yearly counter table.
* Architecture: Moved native Editor Tools into the separate Simple Editors plugin and exposed a portal extension hook.

= 3.4.17 =
* Security: Hardened assessment REST responses so staff auditors cannot receive all assignment submissions unless they can grade that specific assignment.
* Improvement: Restricted staff-auditor management to administrators, officers, and assigned lecturers.
* Improvement: Added audit-log events for staff auditor enrollment, completion, and removal.
* Hardening: Added explicit database formats for staff audit enrollment writes and added a direct-access guard to the student removal service.
* Fix: Corrected the staff audit enrollment listing query used by the Auditors panel and removal flow.

= 3.4.16 =
* Added staff/lecturer audit enrollments through a separate audit relationship so auditors do not enter official student enrollment, attendance, or results.
* Added the lecturer-only Auditing chip and Auditing dashboard block, shown only when the lecturer has active audit enrollments.
* Allowed active staff auditors to view learning content, submit assignments, and participate in discussions for audited subjects.
* Marked staff-auditor submissions with an Auditor kicker in submission metadata, review cards, text downloads, and batch ZIP filenames.
* Kept auditor grades and feedback visible for review while excluding audit submissions from official gradebook and results calculations.
* Split learning, auditing, and teaching permission checks so audit access does not grant attendance, gradebook, content editing, or roster-management privileges.

= 3.4.14 =
* Removed the optional broadcast email checkbox and backend email-queue coupling from Broadcast Center.
* Added administrator-only permanent broadcast deletion for published and archived broadcasts.
* Deleting a broadcast removes its target and recipient records, and clears any pending legacy broadcast-email queue rows tied to that broadcast.
* Added Media Library attachment support for broadcast flyers, photos, posters, and introduction videos.
* Broadcast popups now render attached images and videos inside the polished scrollable message area.
* Added a broadcast media migration to store attachment IDs safely on LMS broadcast messages.
* Kept in-app Broadcast Center publishing, popup, acknowledgement, archive, and mobile modal behavior intact.

= 3.4.13 =
* Polished the Broadcast popup modal for mobile sticky-header layouts by positioning it lower on small screens.
* Matched the Take Attendance modal pattern by keeping the popup shell fixed and scrolling only the message body.
* Added rounded, inset scrollbar styling for long broadcast messages.
* Kept broadcast title, type details, and acknowledgement actions visible while long messages scroll.


= 3.4.12 =
* Fixed the Broadcast Center Publish Broadcast and Archive Broadcast actions by wiring them into the frontend portal action handler.
* Added optional email broadcasting to selected recipients through the existing Simple LMS notification queue.
* Added recipient validation so broadcasts are not published when no active targeted users are found.
* Added safer email handling that skips recipients without valid email addresses instead of falling back to the site notification address.

= 3.4.11 =
* Aligned the Academic Management Subjects chip color with the My Subjects hero chip and subject-card accent.
* Left dashboard block order, scoring, attendance, Results, Broadcast Center, and subject cards unchanged.

= 3.4.10 =
* Changed only the My Subjects hero navigation chip to use the same violet accent as actual subject cards.
* Kept the My Subjects dashboard block on its separate muted indigo accent so subject-card violet remains visually tied to subject navigation and real subject cards.

= 3.4.9 =
* Refined dashboard chip and block accent colors for a consistent muted visual system.
* Changed the Card Issuer hero chip and dashboard block to a distinct muted olive accent so it no longer resembles the Dashboard chip.
* Matched dashboard block accents to their corresponding hero navigation chips where applicable.
* Reserved the existing subject-card violet accent for actual subject cards only.

= 3.4.8 =
* Fixed dashboard accent overlap between Card Issuer and ID Card blocks.
* Card Issuer now uses a dedicated dashboard card class and accent color while ID Card keeps its existing accent.

= 3.4.7 =
* Refined dashboard block accents and removed extra Broadcast Center labels.

= 3.4.6 =
* Refined the Broadcast Center/Announcements dashboard tile to use the same compact LMS block style as other dashboard cards.

= 3.4.5 =
* Reordered dashboard cards by role with Profile Snapshot first and Broadcast Center/Announcements second.
* Removed admin/officer subject preview cards from the dashboard grid.
* Promoted student Upcoming Assignments into the dashboard block grid with direct assignment links.
* Preserved existing Broadcast Center, Announcements, scoring, attendance, and Results behavior.

= 3.4.4 =
* Added a separate Broadcast Center foundation using dedicated broadcast database tables, not the existing frontend announcement post type.
* Added first-position dashboard Announcements/Broadcast Center block with neatly spaced notice cards and LMS-matched visuals.
* Restricted Broadcast Center management to administrators only; all other roles see user-facing Announcements.
* Added in-app broadcasts for all students, all staff, and selected class/subject student groups.
* Added popup/modal reading experience with top close buttons for normal announcement modals and non-acknowledgement login popups.
* Added critical policy acknowledgement tracking and dashboard action-required counts.

= 3.4.3 =
* Added staff-only Results Weight Summary showing attendance, assignment total, final exam available weight, and total planned weight.
* Added Assignment Weight Breakdown so lecturers can see which assignments affect Results and which are 0% practice/non-counting tasks.
* Added final exam remainder visibility and warnings when assignment weights exceed the Results cap.
* Improved student own-result breakdown with clearer status, score, max-points, contribution, and late-penalty labels.
* Added shared Results assignment contribution explanation helper for consistent Results breakdowns without changing the grading form or scoring formula.

= 3.4.2 =
* Added lecturer-friendly attendance current week/class focus controls.
* Added Jump to Current Week/Class and week selector on the Take Attendance tab.
* Highlighted the current attendance card and incomplete current session without changing attendance scoring.

= 3.4.1 =
- Added lecturer grading UX improvements for assignment submissions.
- Added subtle blue, red, and green submission review cues for ungraded, late, and graded work.
- Added AJAX grade and feedback saving so assignment pages no longer refresh after grading.
- Preserved fallback non-JavaScript grading form behavior.
- Prevented feedback-only saves from silently marking blank scores as graded.

= 3.4.0 =
* Fixed assignment result inflation caused by hidden decimal assignment max-point values, such as 7.5/10 appearing as 7.89 when the stored denominator was 9.5.
* Assignment maximum points now save and calculate as whole numbers, while lecturer-entered student scores can still use decimals.
* Existing decimal assignment max-point values are normalized on upgrade, with the original value backed up in assignment post meta.
* Students can still see the Students roster for their class, but full Gradebook data is restricted to staff and students only see their own Results view.

= 3.3.56 =
* Stores Yangon academic timezone (Asia/Yangon, UTC/GMT +6:30) for LMS date and time handling without forcing the global WordPress site timezone.
* Normalizes assignment deadlines to MySQL datetime format while keeping datetime-local fields readable in assignment editors.
* Restyled the main Submit Assignment opener with a solid subtle blue background, no gradient, and no hover movement/effect.
* Renamed the inner submission form button to Submit.
* Flattened the assignment submission form layout to match the cleaner mobile experience with fewer nested visual containers.
* Changed Late Submission Policy and late deadline notices to a subtle red notice style.

= 3.3.54 =
* Moved the always-visible late submission policy into the assignment submission form after the submit button, with added card padding for better readability.
* Removed lift and shadow effects from the assignment submission trigger and submit button; hover/focus now changes only color and border treatment.
* Changed the default assignment submission type to typed answer or file upload for frontend and wp-admin assignment creation fallbacks.
* Updated student and lecturer documentation with the late submission policy.

= 3.3.53 =
* Added late assignment score adjustment logic: 5% deduction per started late day after the due date, up to 10 days.
* Allows students to submit or resubmit assignments after the due date until grading; assignments more than 10 days late can be graded but contribute 0 toward final results.
* Preserves lecturer raw scores while applying late multipliers only to weighted assignment contribution and linked gradebook calculations.
* Adds late-penalty details to assignment result breakdowns, assignment submission panels, grading views, and assignment emails.

= 3.3.52 =
* Added assignment-specific submission confirmation and grade posted emails with subject name, assignment name, section/class context, timestamps, attempt details, scores, and feedback.
* Added unique assignment email subject lines using subject name, assignment title, and assignment ID to reduce stacked/confusing inbox threads.
* Centralized assignment notification email composition so the frontend portal and legacy admin assessment screen send consistent assignment emails.

= 3.3.51 =
* Replaced the generic Students and Results subject-card icons with each student's profile avatar.
* Removed the duplicate inline avatar beside student names in those two subject sections so the card header stays cleaner.

= 3.3.50 =
* Removed the leftover helper text below discussion reply textareas after the formatting toolbar was removed.
* Kept discussion reply boxes clean with only the reply label, textarea, and action buttons.

= 3.3.49 =
* Removed the rich-text formatting toolbar from discussion replies, discussion topics, subject materials, assignments, typed submissions, file-upload notes, and feedback boxes.
* Replaced the shared visual editor with clean plain text textareas while preserving line breaks after saving.
* Removed the toolbar command JavaScript so bold, italic, bullet, and numbering controls no longer appear or run anywhere in the LMS portal forms.

= 3.3.48 =
- Replaced uploaded profile-photo avatars in LMS header and subject header author/kicker areas with stable profile icons so header chips keep the same height as subject codes, assigned lecturers, and other kicker items.
- Fixed rich-text toolbar selection handling so Bold, Italic, Bullet, and Numbering buttons no longer steal focus from the editor or re-toggle the wrong selection. The fix applies to every toolbar instance.

= 3.3.47 =
- Extended the visual rich-text toolbar to subject material descriptions, assignment instructions, student typed submissions, file-submission notes, lecturer feedback, and discussion topic descriptions.
- Kept toolbar actions visual so bold, italic, bullet, and numbered-list formatting is applied directly in the editor instead of inserting visible code.
- Added required-field validation for visual rich-text boxes before saving.

= 3.3.46 =
* Replaced discussion reply toolbar code insertion with a direct visual editor for bold, italic, bulleted lists, and numbered lists.
* Changed bullet and numbering toolbar controls to icon buttons.
* Kept edit-reply forms hidden until Edit Reply is chosen, and restored the original rich editor content when Cancel is clicked.

= 3.3.45 =
* Kept reply edit forms hidden until the three-dot menu's Edit Reply option is clicked, with Cancel closing and resetting the form.
* Updated discussion reply saving and rendering to preserve safe formatting, line breaks, paragraphs, and lists instead of collapsing reply text into one plain line.
* Added a compact formatting toolbar above discussion reply text boxes for bold, italic, bullet lists, and numbered lists.

= 3.3.44 =
* Fixed discussion reply edit forms so the Reply Text, Save, and Cancel controls remain hidden until the three-dot menu's Edit Reply action is selected.
* Fixed Cancel on reply edit forms so it cleanly closes the edit box and restores the reply view.

= 3.3.43 =
* Changed threaded discussion child replies so only a small Reply button appears under each reply by default; the reply form opens only after that button is clicked.
* Added shared round student/staff avatars beside names across portal subject views, assignments, discussions, rosters, results, Attendance Review, Academic Management, and Card Issuer screens.
* Added a bundled blank profile avatar fallback when no uploaded LMS profile photo exists.

= 3.3.42 =
* Raised the managed upload limit from 50MB to 100MB per file for subject files, assignments, student submissions, and discussion attachments.

= 3.3.41 =
* Removed the inline replies helper sentence from subject discussions.
* Replaced visible Edit Reply/Delete Reply buttons with a three-dot reply actions menu.
* Added inline reply editing with Save and Cancel controls.
* Added threaded multi-level discussion replies using WordPress comment parents.

= 3.3.40 =
- Renamed both Academic Management export action buttons to "Download Info" for students and staff, including the matching export submit buttons.

= 3.3.39 =
- Added frontend discussion reply controls so users can edit or delete their own subject-discussion replies, while administrators can manage every reply.
- Added Students and Staff CSV download panels in Academic Management, with batch export support for all matching records and available filters.
- Kept export and reply-management actions protected by existing nonce and role checks.

= 3.3.38 =
- Made registered attendance week/class cards easier to identify by strengthening the green border treatment without changing database behavior.

= 3.3.37 =
- Added more comfortable discussion reply spacing so names and reply text no longer sit too close to reply-card borders.
- Updated assignment displays to use formatted due dates such as "Due: 2026-06-28 12:00 PM" and simplified assignment metadata to due date, points, weight, submission type, and included files/media.
- Closed the student submission editor after the assignment deadline with an inline deadline-passed notice and added a server-side stale-form guard.
- Doubled the lecturer individual-feedback textarea height for easier grading comments.
- Added subtle green borders to already registered attendance week/class cards.

= 3.3.36 =
- Restored the assignment batch ZIP download button for administrators, officers, and lecturers.
- Fixed the production fatal error by loading WordPress file helpers before calling wp_tempnam and adding a safe PHP temp-file fallback.
- Kept server-side role and subject-teaching permission checks before ZIP creation.

= 3.3.35 =
- Temporarily restricted the assignment batch ZIP download control to site administrators only while the batch feature is being repaired.
- Added a server-side administrator-only guard so stale non-admin forms cannot trigger the batch ZIP handler.
- Kept individual submission file/text downloads available for authorized teaching staff.

= 3.3.34 =
- Fixed the lecturer batch assignment ZIP download so it no longer depends only on the PHP ZipArchive extension.
- Added a built-in ZIP fallback writer for hosts without ZipArchive and tightened ZIP entry validation/error handling.
- Cleaned download output buffers before file streaming to prevent corrupted download headers.

= 3.3.33 =
- Added a focused mobile full-bleed layout so the LMS dashboard and subject views use the full available ugp-section width on phones.
- Removed remaining mobile side gutters from LMS structural wrappers while preserving comfortable internal padding inside content panels.
- Kept the mobile assignment submission improvements from 3.3.32.

= 3.3.32 =
- Made the LMS portal and subject assignment views use more of the mobile screen width with reduced nesting and lighter mobile frames.
- Kept the Submit Assignment control while flattening the opened submission area directly inside the assignment on mobile.
- Enlarged typed assignment answer fields to provide an edge-to-edge mobile writing area with comfortable height and touch-friendly controls.
- Expanded Attendance Review on laptop and large screens so subject attendance matrix tables use the available screen width for faster at-a-glance review.

= 3.3.31 =
- Flattened Attendance Review matrix tables by removing the extra table wrapper while keeping horizontal scrolling functional.
- Made Attendance Review student blocks collapsed by default so names expand only when clicked.
- Added student phone numbers beside student ID and email in Attendance Review headers when available.

= 3.3.30 =
- Enlarged Take Attendance student profile portraits so student cards feel fuller while keeping the Late and Leave corner controls intact.

= 3.3.29 =
- Simplified the frontend Attendance Review page so program groups contain flatter, cleaner student blocks instead of nested card stacks.
- Added mobile collapsible student attendance blocks for easier review on small screens.
- Moved student assignment submission panels into the main assignment content area, widened submission controls, and enlarged typed-answer fields for mobile-friendly work.

= 3.3.28 =
- Fixed Take Attendance modal scrolling for large rosters by moving the scroll container into a dedicated modal body.
- Added a student-centered frontend Attendance Review matrix grouped by program, with week-based and class-based subject tables.
- Updated Attendance Review ranges to Last 7 days, Last 4 weeks, and Last 16 weeks.

= 3.3.27 =
* Moved the Take Attendance modal roster scrolling into the modal content area so the scrollbar sits inside the rounded window instead of attaching awkwardly to the outer edge.

= 3.3.26 =
* Added a frontend LMS dashboard Attendance Review card/page for administrators and officers.
* Grouped frontend Attendance Review records by program first, then subject, with student rows for absent, leave, and optional late records.
* Moved Late and Leave exception buttons into the top-left/top-right corners of attendance student cards so profile photos keep the original visual size.

= 3.3.25 =
* Fixed subject, assignment, discussion, and reply media rendering so uploaded videos, embedded media, and photos preserve their natural aspect ratio and fit mobile screens.
* Added secure password update fields to academic management student and staff edit forms.

= 3.3.24 =
* Changed attendance scoring so the reserved 10-mark attendance component is calculated proportionally from the attendance percentage, for example 100% = 10, 75% = 7.5, and 60% = 6.
* Added Leave and Late support to the lecturer attendance-taking cards while preserving the existing click-card Present/Absent toggle workflow.
* Removed Excused from active attendance choices, added a safe legacy cleanup to convert old Excused records to Present, and made Leave/Absent count as zero while Late counts as half a class.
* Added an administrator/officer Attendance Review block with 7-day, 30-day, and 3-month filters for recent absent and leave records, with optional late inclusion.

= 3.3.23 =
* Updated ID photo handling so newly selected photos save the full original by default, while Crop & Save remains available for manual adjustments.
* Changed generated ID card photo rendering to show the full uploaded photo instead of forcing a cover crop, with a white photo background to avoid exposing the blue template behind non-square images.

= 3.3.22 =
* Fixed the generated ID card photo frame so the uploaded photo sits 0.5px inside the subtle white border on every side, removing the tiny blue gap between the photo and border.

= 3.3.21 =
* Removed the Upload / Edit Photo control from the student and staff self-service ID card page.
* Limited frontend ID photo updates to administrators using the Card Issuer preview workflow.
* Kept administrator photo upload/edit support available on the Card Issuer preview page.

= 3.3.20 =
* Added an Upload / Edit Photo control to the Card Issuer preview for administrators.
* Administrators can now update student and staff ID photos from the native WordPress-admin Card Issuer preview.
* Frontend Card Issuer preview also supports updating the selected profile's ID photo with the existing crop/save/remove photo modal.
* Photo updates return to the same Card Issuer preview and refresh the displayed ID card photo.

= 3.3.19 =
* Added per-student manual Assignment score editing in the frontend Results tab for lecturers, officers, and administrators.
* Assignment scores can now be entered alongside Attendance and Final Exam scores, with validation against the subject's allocated assignment weight.
* Blank Assignment score fields continue to use the calculated score from graded LMS submissions, preserving existing online assignment workflows.
* Updated the live Results total/grade preview so manual Assignment score edits are reflected before saving.

= 3.3.18 =
* Adjusted student dashboard Upcoming Assignments so the deadline displays as plain inline text, for example "Deadline: July 7, 2026", instead of a separate chip/background.
* Added final targeted spacing for student submission review summaries so the student name and View action are no longer crowded against the top edge.
* Kept the same Simple LMS assignment card theme while preserving the existing submission review behavior on desktop and mobile.

= 3.3.17 =
* Changed student dashboard Upcoming Assignments so dashboard deadline chips show the assignment date without the time.
* Redesigned the collapsed Submit Assignment control and expanded submission options window with a cleaner Simple LMS themed card treatment.
* Polished teacher Student Submissions review cards with better padding, softer borders, improved spacing, and safer text wrapping.
* Polished the student Your Submission window so submitted work, typed answers, downloads, and feedback have more comfortable internal spacing.

= 3.3.16 =
* Improved student dashboard upcoming assignment cards with clearer spacing, stronger card structure, safer text wrapping, and mobile-friendly deadline/weight chips.
* Restyled the student Submit Assignment controls with a subtle Simple LMS blue treatment and removed the harsh dark border.

= 3.3.15 =
* Changed the student assignment submission editor so first-time submissions start collapsed behind a single Submit Assignment button.
* Kept typed-answer and file-upload options hidden until the student opens the submission editor.
* Tightened the assignment submission editor styling so the closed button and expanded submission panel share clean full-width alignment, including mobile-friendly touch behavior.

= 3.3.14 =
* Added teacher-controlled assignment submission types in the frontend subject workspace: file upload only, typed answer only, or typed answer/file.
* Updated student assignment submission forms so typed answers and file uploads follow the selected submission type.
* Added secure individual teacher downloads for submitted files and typed answers, and expanded batch ZIP downloads to include typed-answer text files.
* Improved the submission review, download, grading, and feedback buttons while keeping the Simple LMS visual theme.
* Improved small-screen material card actions so View and visibility controls stack vertically.
* Clarified permanent material deletion and preserved permanent removal of uploaded material files.

= 3.3.13 =
* Added a mobile-first frontend override layer for the LMS portal while preserving larger-screen layouts.
* Improved phone layouts for portal navigation, grids, cards, forms, action rows, modals, ID card previews, Card Issuer, and Transcript screens.
* Added admin mobile polish for Simple LMS screens inside WordPress admin.

= 3.3.12 =
* Increased generated ID card full name text to 1.1x the previous size.
* Reduced generated ID card Rector text to 0.9x the previous size.

= 3.3.11 =
* Moved ID card full name text down by 5px.

= 3.3.10 =
* Reduced UGP ID card full-name text to 80% and role/program/department text to 90% to better fit longer names on one line.
* Deleting attendance records for a student is now part of subject unenrollment, so class-session counts no longer include unenrolled students.

= 3.3.9 =
* Added a Plan By selector in Take Attendance so lecturers can plan attendance by Weeks or Classes.
* Added breathing room above lecturer-facing Unenroll from Subject actions in subject rosters.
* Strengthened present/absent profile photo indicators in the attendance register.
* Preserved backward compatibility by treating existing attendance plans as Weeks until changed.

= 3.3.8 =
* Added individual Program Completion workflow for students with Pass/Fail result, completion date, notes, and active-enrollment closure that preserves records.
* Added student status filtering including Completed students in Academic Management.
* Added Duplicate Subjects as a separate Subjects action, supporting multi-select duplication into a target term.
* Subject duplication can copy materials, welcome discussion posts, lecturer assignments, and optionally assignments/grading setup without copying students, attendance, marks, or discussion replies.

= 3.3.6 =
* Moved "ugp.edu.mm" down by 5px on the back of the card.
* Increased spacing between ID, NRC, Contact, and Email lines by 3px.


= 3.3.5 =
* Adjusted ID card text positions: full name, Rector, website, and back detail spacing.
* Staff/student ID card views now display the flattened PNG output only, matching the Card Issuer preview output.

= 3.3.4 =
* Fixed ID card issuing records that were saved with an invalid numeric status because of a field format mismatch.
* Added automatic repair for previously affected ID card rows.
* Added draft ID card preview before issuing from Card Issuer.
* Adjusted UGP ID card text positions.

= 3.3.3 =
* Fixed staff-issued ID card lookup in the portal by matching active cards by WordPress user ID, person code, and email.
* Adjusted UGP ID card text positions.
* Kept the student Leave Application feature disabled pending board approval.


= 3.3.2 =
* Fixed ID card text clipping in flattened generated cards by removing restrictive text overflow clipping in the card layout.
* Temporarily disabled the student Leave Application dashboard feature.


= 3.2.7 =

* Reduced the shared header-image modal action buttons to a more compact size while keeping a consistent layout for LMS and subject header editing

= 3.2.6 =

* Removed the obsolete header-image Save Original action and aligned the shared LMS and subject header modal buttons to one consistent size and layout

= 3.2.5 =

* Added a shared header-image opacity slider for LMS and subject headers, with live modal preview and saved per-header opacity settings

= 3.2.4 =

* Widened the Grades settings table inputs so decimal ranges and points are easier to read and edit

= 3.2.3 =

* Unified displayed result outcomes around the subject Results `total_score` calculation across frontend and admin result views
* Updated transcript and transcript print output to show per-subject total score plus overall average and overall grade
* Added a separate grade display in student and teacher Results views that stays hidden until final grading is complete

= 3.2.2 =

* Added a dedicated Grades admin submenu with configurable plus/minus or standard grade-band settings, fail-letter selection, and optional grade points
* Unified transcript and subject Results grade-letter calculation behind the shared grade scale settings so student and teacher views follow the same institution-wide grading rules

= 3.2.1 =

* Removed the left padding from the Installed Migrations list in the wp-admin dashboard card

= 3.2.0 =

* Moved Audit & Queue and Installed Migrations into the main wp-admin dashboard overview grid so they match the same 4-up card sizing as the other admin cards

= 3.1.16 =

* Removed the wp-admin dashboard Quick Actions panel and moved Installed Migrations into a matching card beside Audit & Queue

= 3.1.15 =

* Removed the decorative top-right admin header graphic generated by the page-header pseudo-elements

= 3.1.14 =

* Updated the digital transcript export so all printed text uses normal weight and the remaining four table rules use a thinner, subtler blue

= 3.1.13 =

* Reduced the printed transcript university name weight, removed non-table divider lines, and simplified the table to the four lighter horizontal rules only

= 3.1.12 =

* Changed transcript export to print from a hidden isolated frame instead of opening a separate browser window, so the browser print preview is triggered directly from the transcript button
* Refined the digital transcript print styling with cleaner top spacing, lighter blue section dividers, and horizontal-only course table rules

= 3.1.11 =

* Promoted the shared header-image editor modal to a true top-layer overlay above the site chrome and kept the LMS and subject header editors on the same shared cropper flow
* Replaced transcript export with an isolated digital transcript print window so `Print / Save PDF` generates only the transcript document instead of printing the website page

= 3.1.10 =

* Changed the LMS and subject header image editor into a true cover-style cropper that auto-fills the preview frame on upload and then lets users drag and zoom the image inside it
* Replaced transcript print/export with a dedicated digital academic transcript print sheet containing only the official logo, university name, transcript title, student details, completed-course table, and CGPA

= 3.1.9 =

* Reworked the LMS and subject header image modal into a wider full-header preview with fixed cover-style cropping plus drag-and-zoom positioning
* Refined the student transcript presentation to use pure white document surfaces and added a print/PDF-only official transcript layout with logo, institution details, and print footer

= 3.1.8 =

* Added a student leave-application card and frontend leave-request page with subject selection, date range, total-day calculation, reason text, and validated supporting-document upload
* Routed leave applications through the existing Simple LMS mail pipeline with configurable registrar and academic-coordinator recipient emails plus the selected subject lecturer(s)
* Logged each leave application submission in the audit log and sent the email using the student applicant identity

= 3.1.0 =

* Neutralized the legacy UGP wrapper title so the old `Learning Portal` heading no longer flashes during LMS page loads
* Reworked subject and academic-management action areas into compact left-aligned trigger buttons with separate bordered content panes below
* Removed the extra dashboard, academic-management overview, my-subjects, ID, and security intro headings/copy for a cleaner portal flow
* Updated the subject enrolment sample codes to `UGPS-26001, UGPS-26015` and simplified the academic-management overview to just the five management cards

= 3.0.0 =

* Reworked the frontend LMS pages into unified single-shell layouts so the header and page content now read as one coherent flow instead of separate floating windows
* Reduced the outer page-shell radius and converted top-level dashboard, subject, security, ID, transcript, and academic management sections into internal divided sections while preserving inner cards

= 2.1.20 =

* Changed the Save ID export so the downloaded ID card is rendered with square outer corners instead of the live rounded card radius

= 2.1.19 =

* Added a frontend-only per-user Greyscale toggle in the LMS header and saved the preference per user without affecting other users
* Remapped the main portal visual palette to grayscale when enabled while keeping subject content images and videos untouched

= 2.1.18 =

* Changed the LMS header university title and subject header title back to the clearer pre-visual-rework `Lato` heading font

= 2.1.17 =

* Confirmed LMS header image editing stays limited to administrators and officers, and narrowed subject header image editing to users specifically assigned as lecturers on that subject
* Changed the LMS header Active Subjects chip to count only directly assigned teaching subjects for staff and to hide entirely when the user has zero active subjects
* Updated the LMS header subject-count label to use title case as `Active Subject` / `Active Subjects`

= 2.1.16 =

* Fixed the subject item visibility eye toggle so its click is no longer blocked by the accordion summary event guard before submit
* Simplified the full-page reload submit path for visibility changes so the hidden form posts directly and the item state now updates reliably

= 2.1.15 =

* Restored the subject item visibility toggle to a normal full page reload flow so visibility changes rely on the server-rendered subject tab state instead of AJAX tab refreshes
* Kept the toggle guarded against opening or closing the accordion before submit while returning to the more reliable page reload behavior

= 2.1.14 =

* Changed the subject item visibility toggle refresh flow to rerender the whole current Materials, Assignments, or Discussions window after each toggle instead of only one accordion
* Kept the toggle fully AJAX-based so the page itself does not reload while still reflecting the updated hidden or visible state across the tab window

= 2.1.13 =

* Fixed the subject item visibility toggle so it updates the accordion state, eye icon, and hidden styling reliably after the AJAX response
* Refreshed the server-side visibility result after each toggle so hidden materials, assignments, and discussions stay hidden from students and visibly greyed for staff

= 2.1.12 =

* Reworked the header image edit control into a plain oversized icon action so it sits on the header row without the old chip treatment
* Restored the visible or hidden eye toggle across Subject Materials, Assignments, and Discussions and kept the item-level AJAX visibility flow in place
* Tightened the item visibility markup and messaging so toggled accordion items rerender cleanly without full-page notices

= 2.1.11 =

* Enlarged the header image-edit icon, matched the subject header shell more closely to the main LMS header, and removed the extra panel wrapper from the subject banner
* Increased header background image visibility slightly and added a subtle white title glow so the LMS and subject names stay readable over photos

= 2.1.10 =

* Replaced the header-image text button with an icon-only image-edit control that sits on the same bottom row as the header chips and fades in on hover
* Reworked the LMS and subject header image modal into a wide header-ratio preview with Save Original, Crop & Save, and Remove Image actions
* Added wide-aspect header cropping so uploaded banner images can be trimmed to the header frame before saving

= 2.1.9 =

* Moved the material visibility eye toggle to an inline AJAX update so only the affected accordion item rerenders, with no full-page refresh and no visibility notice banner
* Added editable header-image support for the main LMS header and individual subject headers, including bottom-right edit controls and reusable upload/remove handling
* Reworked subject accordion row alignment so the file-type icon and action buttons sit vertically centered against the full accordion summary height

= 2.1.8 =

* Pulled the LMS header action chips down to the lower-left edge, matched the single-subject header panel more closely to the main LMS header, and corrected subject-card title alignment beside the subject icon
* Refined subject accordion summaries with clearer vertical centering, a slightly larger title, and more breathing room between the excerpt line and the info chips
* Fixed the material visibility eye toggle so it preserves the accordion’s current open or closed state instead of reopening the item after the visibility change

= 2.1.7 =

* Removed the dashboard eyebrow chips from the primary workspace cards and changed dashboard/My Subjects subject cards to a shared layout with plain left-rail subject codes under the subject icon
* Matched the LMS identity chips and subject-view metadata chips to the main portal kicker color, tightened the Subject Workspace chip width, and expanded the subject hero to a full header-height panel
* Reworked subject accordion summaries to include a single-line excerpt and fixed the visibility toggle so changing hidden/visible no longer opens or closes the accordion item

= 2.1.6 =

* Rebuilt My Subjects and Academic Management overview cards into a tighter single-line icon-and-title layout and matched My Subjects cards to the shared dashboard subject-card style
* Hid the main LMS header inside individual subject workspaces, compacted the subject hero into chip-based code and lecturer metadata, and removed live counters from Results and Take Attendance
* Shrunk subject material, assignment, and discussion accordion summaries further and aligned the visibility toggle button to the same pill shape and size as the View control

= 2.1.5 =

* Removed the subject hero stat tiles and turned the subject navigation chips into live count chips in the new Materials, Assignments, Discussions, Students, Results, and Attendance order
* Tightened the subject material, assignment, and discussion accordion summaries for a more compact title-and-meta layout

= 2.1.4 =

* Added top breathing room above the main LMS hero, exposed lecturer names in subject workspaces and active subject cards, and renamed the subject People tab to Students
* Added lecturer-side material visibility toggles with student-safe filtering, hidden-state styling, and updated View/Close accordion actions for materials, assignments, and discussions
* Re-synced Academic Management overview card accents with their matching section chip colors

= 2.1.3 =

* Removed the duplicate Academic Management chip row and moved the live record counts into the actual section chips
* Synced the overview Programs, Terms, Subjects, Students, and Staff cards to the same accent colors used by their matching Academic Management chips

= 2.1.2 =

* Merged the Academic Management heading and live overview chips into the main management panel so the overview page now opens inside a single unified shell
* Fixed the restored ID card watermark to use the provided logo source at a stronger 0.08 opacity for the full-height background mark

= 2.1.1 =

* Compacted the Academic Management hero, removed the overview spreadsheet-import section, and added cleaner spacing around Programs, Terms, and expanded management accordions
* Restored the portal ID card to its pre-rework design and changed subject file repeaters to a text-style `+ Add another file` control while keeping the same behavior

= 2.1.0 =

* Removed the remaining legacy dashboard-hero width constraints that were forcing the portal header into a phone-width column on desktop screens
* Reworked the frontend portal color system so stats, academic sections, action chips, management cards, and active states use clearer distinct accents with stronger text contrast
* Refined the admin and login visual palette to use the updated accent system and page-level color differentiation for the new build

= 2.0 =

* Delivered a full visual redesign across the Simple LMS admin dashboard, frontend portal, editor workspace, and login experience
* Reworked page identities, section hierarchy, card treatments, icons, tables, forms, and empty states into a unified premium academic interface
* Shifted the overall system to a calm white-first palette with muted institutional accents for improved comfort and readability during extended use

= 1.3.62 =

* Added direct lecturer unassignment controls on subject records for administrators and officers in Academic Management
* Added an administrator-only force remove subject action that bypasses the normal guardrails and permanently deletes linked materials, assignments, discussions, enrolments, grades, attendance, and submission files before removing the subject

= 1.3.61 =

* Fixed subject and submission accordion toggles so collapsed items consistently show the eye icon with `View`, while expanded items switch to a large minus sign for collapse
* Adjusted the lecturer attendance modal header spacing so the present/absent chip no longer crowds the modal close button
* Reworked the frontend portal hero heading to show the institution name in place of `Simple LMS` with improved spacing above the signed-in user line

= 1.3.60 =

* Changed attendance percentage calculations to use the subject's planned class total instead of only recorded meetings, so a student who attends 1 class in a 10-class course now shows 10 percent attendance
* Adjusted the attendance denominator for students who enrolled after earlier completed classes so they are only measured against the planned classes still applicable to their enrollment window

= 1.3.59 =

* Let students start new discussion topics from the subject discussions tab while keeping edit and delete controls with staff
* Reworked assignment submission and grading flows with student resubmission editing before the deadline, submission confirmation emails, grading emails, lecturer submission accordions, and ZIP download for all submitted files
* Rebuilt attendance into compact 4:3 week cards, fixed-size portrait student toggles, and lecturer attendance modals instead of long inline registers
* Added an upcoming assignments card to the student dashboard and rebuilt subject results with the new attendance-and-participation rule, compact student tables, and lecturer-editable result rows
* Enforced the 90 percent assignment-weight cap in subjects so the remaining 10 percent stays reserved for attendance and participation

= 1.3.58 =

* Changed account welcome emails to send immediately during account creation instead of waiting for the notification cron queue
* Updated the notification dispatcher so welcome emails respect the dedicated welcome-email setting and record send failures more clearly

= 1.3.57 =

* Rewrote the account welcome email with the approved University of Global Peace greeting, login credentials, first-login password-change reminder, and Academic Support Team signature
* Changed the welcome email subject line to `Welcome to the University of Global Peace`

= 1.3.56 =

* Changed subject material, assignment, and discussion uploads to plugin-managed files under the WordPress uploads folder so new portal files no longer create Media Library entries
* Added per-file attachment removal inside the subject item edit forms and now permanently delete removed files instead of only unlinking them
* Moved portal profile-photo uploads into the plugin-managed file storage and clean up replaced or deleted photos automatically
* Added SMTP connection testing and test-email tools directly in the Simple LMS settings page for the saved welcome-email mailer configuration

= 1.3.55 =

* Updated the branded login screen to use the current `ugp.edu.mm` large logo asset
* Reworked subject material, assignment, and discussion accordion summaries with new leading icons, typed file-count chips, and a `View` action that turns into a minus icon when expanded
* Changed subject item deletion to permanently remove linked media-library attachments instead of leaving uploaded files behind

= 1.3.54 =

* Changed subject material, assignment, and discussion file uploads to progressive single-file inputs that reveal an `Add Another File` option only after a file is chosen
* Applied the same progressive file-adding flow to subject item edit forms so staff can add attachments one file at a time

= 1.3.53 =

* Flattened subject material, assignment, and discussion accordion expansions so the full item content now sits directly in the accordion body instead of a nested inner window
* Removed the boxed image-gallery treatment in expanded subject items and now display uploaded images inline in their natural ratios
* Flattened discussion replies and media sections inside expanded accordions so replies, files, audio, and embedded media read as part of one continuous item view

= 1.3.52 =

* Fixed stale staff and student counts by ensuring profile totals only include users that still exist in WordPress
* Added backend cleanup hooks so deleting a WordPress user also removes the matching LMS profile record
* Added a one-time orphan profile purge after update so deleted imports no longer linger in portal counts or block re-imports by person code

= 1.3.51 =

* Replaced separate subject item pages with inline accordions for materials, assignments, and discussions so each item expands directly inside the LMS subject tab
* Added inline assignment submission grading for lecturers, including student file downloads, individual feedback, and per-submission scoring inside each assignment accordion
* Expanded the student results view with accumulated weighted score tracking and assignment-level weighted contribution details
* Updated subject image rendering to preserve original aspect ratios and kept audio filenames visible above each player

= 1.3.50 =

* Changed subject materials, assignments, and discussions so item cards now open in the same LMS workspace as an internal subject-item window instead of a separate page/tab
* Added a dedicated Back button at the top of each subject-item window to return users directly to the parent Materials, Assignments, or Discussions view
* Added assignment weight beside assignment points in the assignments list cards for quicker at-a-glance review

= 1.3.49 =

* Rebuilt subject materials, assignments, and discussions into dedicated premium item pages with cleaner card listings and new-window detail views
* Added multi-file attachments up to 100MB per file for subject materials, assignments, and discussion threads, including document downloads, image galleries, audio players, and embedded media support
* Added frontend edit and delete workflows for materials, assignments, and discussion threads for administrators, officers, and assigned lecturers
* Moved assignment submission and discussion replies into the dedicated subject item pages and added a full-screen image viewer plus audio speed controls

= 1.3.48 =

* Reworked the frontend Security password-change form into three stacked fields with a narrower layout and a left-aligned primary action
* Replaced the frontend ID card brand strip with the current `ugp.edu.mm` asset
* Added in-plugin SMTP settings for queued emails, including custom sender address, sender name, server, port, encryption, and authentication
* Routed queued welcome emails through the new SMTP-aware mail configuration with HTML email headers

= 1.3.47 =

* Fixed the first-login crash for newly provisioned accounts by removing the fatal password-setup guard path
* Added a full frontend Security view in the LMS portal so users can change their passwords without leaving the portal
* Forced new student and staff accounts to land on the portal security screen until they complete first-login password setup
* Added the legacy first-login password-change flow for newly provisioned student and staff accounts
* Updated the people creation screens and welcome notification copy to explain the first-login password setup flow

= 1.3.45 =

* Changed the standalone Editor Tools page so logged-out visitors see a login prompt with a Log In button instead of being redirected immediately

= 1.3.44 =

* Refactored Editor Tools out of the LMS portal into a dedicated frontend page template that can be assigned to a standalone WordPress page
* Restricted the standalone Editor Tools page to administrators, officers, and editors only
* Removed Editor Tools from the LMS portal header and dashboard workspace so publishing now lives outside the LMS experience

= 1.3.43 =

* Switched auto-generated student and staff IDs to the shorter UGPS-26XYZ and UGPF-26XYZ format with random three-digit yearly suffixes
* Made person IDs the default usernames for newly provisioned student and staff accounts
* Kept welcome emails enabled by default and continued sending username, password setup link, and dashboard link
* Enforced first-login password setup by blocking direct login until the emailed setup link has been completed

= 1.3.42 =

* Added guarded remove actions for programs, terms, subjects, students, and staff in the frontend academic and people editors
* Added remove buttons beside Save and Cancel in the inline edit state for those records
* Prevented deletions when academic records still depend on the item being removed

= 1.3.41 =

* Made program codes required when creating or updating programs in the frontend academic tools
* Switched subject imports to resolve program assignments by program code and updated the subject CSV/XLSX templates accordingly

= 1.3.34 =

* Replaced random student and staff person-code generation with yearly institutional sequences such as UGPSTU-26001 and UGPSTA-26001 while preserving manual overrides
* Added separate annual counters for student and staff IDs and aligned the default staff type token to STA

= 1.3.33 =

* Reduced the logo-to-form gap by 5px and rolled the password-toggle centering override into the packaged release

= 1.3.32 =

* Forced the password visibility button and dashicon to use the same fixed square box so their centers align exactly inside the password field

= 1.3.31 =

* Centered the password visibility icon within its button by converting the dashicon span to an inline-flex box with a centered pseudo-element

= 1.3.30 =

* Rebuilt the password row on the branded login page to use a fixed-height input track so the password visibility button is centered directly against the password field

= 1.3.29 =

* Reduced the logo-to-form gap by about 15px, enlarged the login logo, unified the label-to-field and Remember Me-to-button spacing, and rebuilt the password row so the visibility icon is centered against the input row itself

= 1.3.28 =

* Enlarged the branded login logo, added about 30px more space between the logo and the login form, and corrected the password visibility icon alignment inside the password field

= 1.3.27 =

* Slightly reduced the branded login form width, increased the gap below the university logo, and centered the password visibility button directly within the password field

= 1.3.26 =

* Normalized the username and password label-to-field spacing on the branded login page and added more room between the university logo and the login card

= 1.3.25 =

* Rebuilt the branded login form layout so the Remember Me row and submit button use structural spacing, and added more room between the university logo and the login card

= 1.3.24 =

* Removed the custom login helper section and changed the Remember Me to submit-button spacing to use non-collapsing padding for a stable visible gap

= 1.3.23 =

* Increased the gap between the Remember Me row and the login button, slightly enlarged the university title, and changed the helper text to “Log in with your university account.”

= 1.3.22 =

* Slightly enlarged the university title on the branded login page and moved the Remember Me row upward for a tighter form layout

= 1.3.21 =

* Increased the spacing between the Remember Me row and the login button on the branded login page

= 1.3.20 =

* Slightly enlarged the university title on the branded login page while keeping it constrained to a single line

= 1.3.19 =

* Further tightened the branded login page with more space above the submit button, a smaller single-line university title, and slightly shorter email/password fields

= 1.3.18 =

* Tightened the branded login page with a slightly narrower and shorter form card, a smaller university heading, and a shorter login helper message

= 1.3.17 =

* Refined the branded WordPress login page with a narrower form card, centered password-visibility button, simplified university-only heading, and removal of the faint background logo

= 1.3.16 =

* Added a dedicated Simple LMS branded WordPress login page design using the university logo and LMS visual system
* Stopped redirecting core `/wp-admin/index.php` requests so the native WordPress Dashboard button opens the real admin dashboard

= 1.1.0 =

* Added frontend-native ID card tab with profile photo upload, local cropper support, and print-ready layout
* Added professional transcript view with completed-course grouping, grade letters, CGPA summary, and print styling
* Added frontend publish-date support for news and announcements, plus frontend editing for publish date and featured-image caption
* Reworked portal containment so the LMS fits the website width without the legacy UGP wrapper border
* Improved single-subject frontend workflow with dedicated subject list and full-page subject workspace tabs

= 1.0.0 =

* First packaged release of the unified Simple LMS plugin
* Added dashboard-first academic, teaching, assessment, attendance, and records workflows
* Added People Directory and Academic Records Hub
* Added release packaging files for install-ready distribution
