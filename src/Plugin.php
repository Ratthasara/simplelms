<?php

namespace SimpleLMS;

use SimpleLMS\Core\ServiceContainer;
use SimpleLMS\Domain\Academic\AttendanceService;
use SimpleLMS\Domain\Academic\EnrollmentService;
use SimpleLMS\Domain\Academic\LeaveApplicationService;
use SimpleLMS\Domain\Academic\TermService;
use SimpleLMS\Domain\Assessment\AssessmentService;
use SimpleLMS\Domain\Assessment\GradebookService;
use SimpleLMS\Domain\Assessment\GradeScaleService;
use SimpleLMS\Domain\Assessment\ResultsService;
use SimpleLMS\Domain\Communication\BroadcastService;
use SimpleLMS\Domain\Communication\AudienceResolver;
use SimpleLMS\Domain\Communication\EmailCampaignService;
use SimpleLMS\Domain\Learning\WorkspaceService;
use SimpleLMS\Domain\Reporting\RecordsService;
use SimpleLMS\Domain\Settings\SettingsManager;
use SimpleLMS\Domain\Users\ProfileService;
use SimpleLMS\Domain\Users\ProvisioningService;
use SimpleLMS\Domain\Users\StudentRemovalService;
use SimpleLMS\Infrastructure\Academic\AcademicRegistrar;
use SimpleLMS\Infrastructure\Assessment\AssessmentRegistrar;
use SimpleLMS\Infrastructure\Admin\AcademicCoreAdmin;
use SimpleLMS\Infrastructure\Admin\AdminAssets;
use SimpleLMS\Infrastructure\Admin\AdminMenu;
use SimpleLMS\Infrastructure\Admin\AssessmentsAdmin;
use SimpleLMS\Infrastructure\Admin\AttendanceAdmin;
use SimpleLMS\Infrastructure\Admin\GradebookAdmin;
use SimpleLMS\Infrastructure\Admin\PeopleAdmin;
use SimpleLMS\Infrastructure\Admin\RecordsAdmin;
use SimpleLMS\Infrastructure\Admin\WorkspaceAdmin;
use SimpleLMS\Infrastructure\Frontend\AcademicManagementTools;
use SimpleLMS\Infrastructure\Frontend\PortalExperience;
use SimpleLMS\Infrastructure\Frontend\PortalGateway;
use SimpleLMS\Infrastructure\Import\ImportService;
use SimpleLMS\Infrastructure\IdCards\IdCardsAdmin;
use SimpleLMS\Infrastructure\IdCards\IdCardsModule;
use SimpleLMS\Infrastructure\Installer;
use SimpleLMS\Infrastructure\Learning\DiscussionRegistrar;
use SimpleLMS\Infrastructure\Learning\LearningRegistrar;
use SimpleLMS\Infrastructure\Login\LoginAccessGuard;
use SimpleLMS\Infrastructure\Login\LoginCustomizer;
use SimpleLMS\Infrastructure\Logging\AuditLogger;
use SimpleLMS\Infrastructure\Notifications\AssignmentNotificationService;
use SimpleLMS\Infrastructure\Notifications\NotificationManager;
use SimpleLMS\Infrastructure\Rest\AcademicController;
use SimpleLMS\Infrastructure\Rest\AssessmentController;
use SimpleLMS\Infrastructure\Rest\AttendanceController;
use SimpleLMS\Infrastructure\Rest\GradebookController;
use SimpleLMS\Infrastructure\Rest\PeopleController;
use SimpleLMS\Infrastructure\Rest\RecordsController;
use SimpleLMS\Infrastructure\Rest\SystemController;
use SimpleLMS\Infrastructure\Rest\WorkspaceController;

defined( 'ABSPATH' ) || exit;

class Plugin {
	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var ServiceContainer
	 */
	private $container;

	/**
	 * @var bool
	 */
	private $booted = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->container = new ServiceContainer();
	}

	public function boot() {
		if ( $this->booted ) {
			return;
		}

		Installer::maybe_upgrade();
		$this->register_services();
		$this->register_service_hooks();

		$this->booted = true;

		do_action( 'slms_loaded', $this );
	}


	private function register_services() {
		$this->container->singleton(
			'audit_logger',
			static function () {
				return new AuditLogger();
			}
		);

		$this->container->singleton(
			'settings',
			function ( ServiceContainer $container ) {
				return new SettingsManager( $container->get( 'audit_logger' ) );
			}
		);

		$this->container->singleton(
			'notifications',
			function ( ServiceContainer $container ) {
				return new NotificationManager(
					$container->get( 'settings' ),
					$container->get( 'audit_logger' )
				);
			}
		);

		$this->container->singleton(
			'audiences',
			static function () {
				return new AudienceResolver();
			}
		);

		$this->container->singleton(
			'broadcasts',
			function ( ServiceContainer $container ) {
				return new BroadcastService(
					$container->get( 'audit_logger' ),
					$container->get( 'audiences' )
				);
			}
		);

		$this->container->singleton(
			'email_campaigns',
			function ( ServiceContainer $container ) {
				return new EmailCampaignService(
					$container->get( 'audiences' ),
					$container->get( 'notifications' ),
					$container->get( 'audit_logger' )
				);
			}
		);

		$this->container->singleton(
			'assignment_notifications',
			function ( ServiceContainer $container ) {
				return new AssignmentNotificationService(
					$container->get( 'assessments' ),
					$container->get( 'notifications' )
				);
			}
		);

		$this->container->singleton(
			'profiles',
			static function () {
				return new ProfileService();
			}
		);


		$this->container->singleton(
			'student_removal',
			function ( ServiceContainer $container ) {
				return new StudentRemovalService(
					$container->get( 'profiles' ),
					$container->get( 'audit_logger' )
				);
			}
		);


		$this->container->singleton(
			'assessments',
			function ( ServiceContainer $container ) {
				return new AssessmentService(
					$container->get( 'enrollments' ),
					$container->get( 'audit_logger' )
				);
			}
		);

		$this->container->singleton(
			'gradebook',
			function ( ServiceContainer $container ) {
				return new GradebookService(
					$container->get( 'enrollments' ),
					$container->get( 'assessments' ),
					$container->get( 'attendance' ),
					$container->get( 'audit_logger' )
				);
			}
		);

		$this->container->singleton(
			'grade_scale',
			function ( ServiceContainer $container ) {
				return new GradeScaleService( $container->get( 'settings' ) );
			}
		);

		$this->container->singleton(
			'results',
			function ( ServiceContainer $container ) {
				return new ResultsService(
					$container->get( 'assessments' ),
					$container->get( 'attendance' ),
					$container->get( 'grade_scale' )
				);
			}
		);

		$this->container->singleton(
			'workspace',
			function ( ServiceContainer $container ) {
				return new WorkspaceService(
					$container->get( 'enrollments' ),
					$container->get( 'profiles' ),
					$container->get( 'assessments' )
				);
			}
		);

		$this->container->singleton(
			'records',
			function ( ServiceContainer $container ) {
				return new RecordsService(
					$container->get( 'terms' ),
					$container->get( 'enrollments' ),
					$container->get( 'attendance' ),
					$container->get( 'results' )
				);
			}
		);

		$this->container->singleton(
			'provisioning',
			function ( ServiceContainer $container ) {
				return new ProvisioningService(
					$container->get( 'profiles' ),
					$container->get( 'settings' ),
					$container->get( 'notifications' ),
					$container->get( 'audit_logger' )
				);
			}
		);

		$this->container->singleton(
			'imports',
			function ( ServiceContainer $container ) {
				return new ImportService(
					$container->get( 'provisioning' ),
					$container->get( 'profiles' ),
					$container->get( 'enrollments' ),
					$container->get( 'terms' )
				);
			}
		);

		$this->container->singleton(
			'admin_menu',
			function ( ServiceContainer $container ) {
				return new AdminMenu(
					$container->get( 'settings' ),
					$container->get( 'audit_logger' ),
					$container->get( 'notifications' ),
					$container->get( 'terms' ),
					$container->get( 'enrollments' ),
					$container->get( 'attendance' ),
					$container->get( 'profiles' ),
					$container->get( 'workspace' )
				);
			}
		);

		$this->container->singleton(
			'admin_assets',
			static function () {
				return new AdminAssets();
			}
		);

		$this->container->singleton(
			'id_cards',
			function ( ServiceContainer $container ) {
				return new IdCardsModule( $container->get( 'profiles' ) );
			}
		);

		$this->container->singleton(
			'id_cards_admin',
			function ( ServiceContainer $container ) {
				return new IdCardsAdmin(
					$container->get( 'profiles' ),
					$container->get( 'id_cards' )
				);
			}
		);

		$this->container->singleton(
			'terms',
			static function () {
				return new TermService();
			}
		);

		$this->container->singleton(
			'enrollments',
			function ( ServiceContainer $container ) {
				return new EnrollmentService(
					$container->get( 'audit_logger' )
				);
			}
		);

		$this->container->singleton(
			'attendance',
			function ( ServiceContainer $container ) {
				return new AttendanceService(
					$container->get( 'enrollments' ),
					$container->get( 'audit_logger' )
				);
			}
		);

		$this->container->singleton(
			'leave_applications',
			function ( ServiceContainer $container ) {
				return new LeaveApplicationService(
					$container->get( 'settings' ),
					$container->get( 'enrollments' ),
					$container->get( 'profiles' ),
					$container->get( 'notifications' ),
					$container->get( 'audit_logger' )
				);
			}
		);

		$this->container->singleton(
			'academic_registrar',
			function ( ServiceContainer $container ) {
				return new AcademicRegistrar(
					$container->get( 'terms' ),
					$container->get( 'enrollments' ),
					$container->get( 'audit_logger' )
				);
			}
		);

		$this->container->singleton(
			'academic_admin',
			function ( ServiceContainer $container ) {
				return new AcademicCoreAdmin(
					$container->get( 'terms' ),
					$container->get( 'enrollments' ),
					$container->get( 'audit_logger' )
				);
			}
		);

		$this->container->singleton(
			'people_admin',
			function ( ServiceContainer $container ) {
				return new PeopleAdmin(
					$container->get( 'profiles' ),
					$container->get( 'provisioning' ),
					$container->get( 'terms' )
				);
			}
		);

		$this->container->singleton(
			'workspace_admin',
			function ( ServiceContainer $container ) {
				return new WorkspaceAdmin(
					$container->get( 'workspace' ),
					$container->get( 'settings' )
				);
			}
		);

		$this->container->singleton(
			'portal_gateway',
			function ( ServiceContainer $container ) {
				return new PortalGateway(
					$container->get( 'settings' ),
					$container->get( 'workspace_admin' ),
					$container->get( 'portal_experience' )
				);
			}
		);

		$this->container->singleton(
			'login_customizer',
			function ( ServiceContainer $container ) {
				return new LoginCustomizer(
					$container->get( 'settings' )
				);
			}
		);

		$this->container->singleton(
			'login_access_guard',
			function ( ServiceContainer $container ) {
				return new LoginAccessGuard(
					$container->get( 'profiles' )
				);
			}
		);

		$this->container->singleton(
			'records_admin',
			function ( ServiceContainer $container ) {
				return new RecordsAdmin(
					$container->get( 'records' )
				);
			}
		);

		$this->container->singleton(
			'assessment_registrar',
			function ( ServiceContainer $container ) {
				return new AssessmentRegistrar(
					$container->get( 'enrollments' ),
					$container->get( 'audit_logger' )
				);
			}
		);

		$this->container->singleton(
			'assessments_admin',
			function ( ServiceContainer $container ) {
				return new AssessmentsAdmin(
					$container->get( 'assessments' ),
					$container->get( 'enrollments' ),
					$container->get( 'assignment_notifications' )
				);
			}
		);

		$this->container->singleton(
			'gradebook_admin',
			function ( ServiceContainer $container ) {
				return new GradebookAdmin(
					$container->get( 'gradebook' ),
					$container->get( 'enrollments' ),
					$container->get( 'results' )
				);
			}
		);

		$this->container->singleton(
			'attendance_admin',
			function ( ServiceContainer $container ) {
				return new AttendanceAdmin(
					$container->get( 'attendance' ),
					$container->get( 'enrollments' )
				);
			}
		);


		$this->container->singleton(
			'academic_management_tools',
			function ( ServiceContainer $container ) {
				return new AcademicManagementTools(
					$container->get( 'imports' ),
					$container->get( 'terms' ),
					$container->get( 'enrollments' ),
					$container->get( 'profiles' ),
					$container->get( 'provisioning' ),
					$container->get( 'student_removal' )
				);
			}
		);

		$this->container->singleton(
			'learning_registrar',
			function ( ServiceContainer $container ) {
				return new LearningRegistrar(
					$container->get( 'enrollments' ),
					$container->get( 'audit_logger' )
				);
			}
		);

		$this->container->singleton(
			'discussion_registrar',
			function ( ServiceContainer $container ) {
				return new DiscussionRegistrar(
					$container->get( 'enrollments' ),
					$container->get( 'audit_logger' )
				);
			}
		);


		$this->container->singleton(
			'portal_experience',
			function ( ServiceContainer $container ) {
				return new PortalExperience(
					$container->get( 'settings' ),
					$container->get( 'terms' ),
					$container->get( 'enrollments' ),
					$container->get( 'assessments' ),
					$container->get( 'gradebook' ),
					$container->get( 'grade_scale' ),
					$container->get( 'results' ),
					$container->get( 'attendance' ),
					$container->get( 'profiles' ),
					$container->get( 'imports' ),
					$container->get( 'academic_management_tools' ),
					$container->get( 'notifications' ),
					$container->get( 'assignment_notifications' ),
					$container->get( 'leave_applications' ),
					$container->get( 'id_cards' ),
					$container->get( 'broadcasts' ),
					$container->get( 'email_campaigns' )
				);
			}
		);

		$this->container->singleton(
			'rest_controller',
			function ( ServiceContainer $container ) {
				return new SystemController(
					$container->get( 'settings' ),
					$container->get( 'audit_logger' ),
					$container->get( 'notifications' )
				);
			}
		);

		$this->container->singleton(
			'academic_rest_controller',
			function ( ServiceContainer $container ) {
				return new AcademicController(
					$container->get( 'terms' ),
					$container->get( 'enrollments' )
				);
			}
		);

		$this->container->singleton(
			'people_rest_controller',
			function ( ServiceContainer $container ) {
				return new PeopleController(
					$container->get( 'profiles' ),
					$container->get( 'provisioning' )
				);
			}
		);

		$this->container->singleton(
			'workspace_rest_controller',
			function ( ServiceContainer $container ) {
				return new WorkspaceController(
					$container->get( 'workspace' )
				);
			}
		);

		$this->container->singleton(
			'assessment_rest_controller',
			function ( ServiceContainer $container ) {
				return new AssessmentController(
					$container->get( 'assessments' )
				);
			}
		);

		$this->container->singleton(
			'gradebook_rest_controller',
			function ( ServiceContainer $container ) {
				return new GradebookController(
					$container->get( 'gradebook' )
				);
			}
		);

		$this->container->singleton(
			'attendance_rest_controller',
			function ( ServiceContainer $container ) {
				return new AttendanceController(
					$container->get( 'attendance' )
				);
			}
		);

		$this->container->singleton(
			'records_rest_controller',
			function ( ServiceContainer $container ) {
				return new RecordsController(
					$container->get( 'records' )
				);
			}
		);
	}

	private function register_service_hooks() {
		$this->container->get( 'profiles' )->register_hooks();
		$this->container->get( 'settings' )->register_hooks();
		$this->container->get( 'notifications' )->register_hooks();
		$this->container->get( 'admin_menu' )->register_hooks();
		$this->container->get( 'admin_assets' )->register_hooks();
		$this->container->get( 'id_cards' )->register_hooks();
		$this->container->get( 'id_cards_admin' )->register_hooks();
		$this->container->get( 'terms' )->register_hooks();
		$this->container->get( 'academic_registrar' )->register_hooks();
		$this->container->get( 'academic_admin' )->register_hooks();
		$this->container->get( 'people_admin' )->register_hooks();
		$this->container->get( 'workspace_admin' )->register_hooks();
		$this->container->get( 'portal_gateway' )->register_hooks();
		$this->container->get( 'login_customizer' )->register_hooks();
		$this->container->get( 'login_access_guard' )->register_hooks();
		$this->container->get( 'records_admin' )->register_hooks();
		$this->container->get( 'assessment_registrar' )->register_hooks();
		$this->container->get( 'assessments_admin' )->register_hooks();
		$this->container->get( 'gradebook_admin' )->register_hooks();
		$this->container->get( 'attendance_admin' )->register_hooks();
		$this->container->get( 'academic_management_tools' )->register_hooks();
		$this->container->get( 'learning_registrar' )->register_hooks();
		$this->container->get( 'discussion_registrar' )->register_hooks();

		if ( ! (int) $this->container->get( 'settings' )->get( 'enable_rest_api', 1 ) ) {
			return;
		}

		$this->container->get( 'rest_controller' )->register_hooks();
		$this->container->get( 'academic_rest_controller' )->register_hooks();
		$this->container->get( 'people_rest_controller' )->register_hooks();
		$this->container->get( 'workspace_rest_controller' )->register_hooks();
		$this->container->get( 'assessment_rest_controller' )->register_hooks();
		$this->container->get( 'gradebook_rest_controller' )->register_hooks();
		$this->container->get( 'attendance_rest_controller' )->register_hooks();
		$this->container->get( 'records_rest_controller' )->register_hooks();
	}
}
