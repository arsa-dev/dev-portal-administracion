<?php

namespace Tests\Unit;


use Mockery;
use Tests\TestCase;
use App\Models\UsersModel;
use App\Models\CoursesModel;
use Illuminate\Http\Request;
use App\Models\UserRolesModel;
use App\Models\CourseTypesModel;
use App\Models\TooltipTextsModel;
use Illuminate\Http\UploadedFile;
use App\Models\CourseStatusesModel;
use App\Models\GeneralOptionsModel;
use App\Services\EmbeddingsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use App\Services\CertidigitalService;
use Illuminate\Support\Facades\Schema;
use App\Models\EducationalProgramsModel;
use App\Http\Controllers\SliderController;
use App\Exceptions\OperationFailedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Controllers\Administration\CarrouselsController;
use App\Http\Controllers\Management\ManagementCoursesController;

class CarrouselsTest extends TestCase
{
    private $imagePath;
    use RefreshDatabase;

    public function setUp(): void
    {

        parent::setUp();
        $this->withoutMiddleware();
        // Asegúrate de que la tabla 'qvkei_settings' existe
        $this->assertTrue(Schema::hasTable('users'), 'La tabla users no existe.');
    }

    /** @test Obtener el Index View Carrousel*/
    public function testIndexRouteReturnsViewCarrousel()
    {

        $user = UsersModel::factory()->create()->latest()->first();
        $roles = UserRolesModel::firstOrCreate(['code' => 'MANAGEMENT'], ['uid' => generate_uuid()]);// Crea roles de prueba
        $user->roles()->attach($roles->uid, ['uid' => generate_uuid()]);

        // Autenticar al usuario
        Auth::login($user);

        // Compartir la variable de roles manualmente con la vista
        View::share('roles', $roles);

        $general_options = GeneralOptionsModel::all()->pluck('option_value', 'option_name')->toArray();
       View::share('general_options', $general_options);

        // Simula datos de TooltipTextsModel
        $tooltip_texts = TooltipTextsModel::factory()->count(3)->create();
        View::share('tooltip_texts', $tooltip_texts);

        // Simula notificaciones no leídas
        $unread_notifications = $user->notifications->where('read_at', null);
        View::share('unread_notifications', $unread_notifications);

        $typecourse1 = CourseTypesModel::factory()->create([
            'uid' => generate_uuid(),
            'name' => 'COURSE_TYPE_1',
        ])->latest()->first();


        $coursestatuses = CourseStatusesModel::factory()->create([
            'uid' => generate_uuid(),
            'code' => 'READY_ADD_EDUCATIONAL_PROGRAM',
        ])->latest()->first();
        $course = CoursesModel::factory()->create([
            'uid' => generate_uuid(),
            'creator_user_uid' => $user->uid,
            'course_type_uid' => $typecourse1->uid,
            'course_status_uid' => $coursestatuses->uid,
            'identifier' => 'identifier',
            'featured_big_carrousel_approved' => 0,
            'featured_big_carrousel' => 0,
        ]);

        EducationalProgramsModel::factory()->withEducationalProgramType()->create([
            'featured_slider_approved' => false,
        ]);

        // Realiza una solicitud GET a la ruta
        $response = $this->get(route('carrousels'));

        // Verifica que la respuesta sea exitosa (código 200)
        $response->assertStatus(200);

        // Verifica que se retorne la vista correcta
        $response->assertViewIs('administration.carrousels.index');

        // Verifica que los datos se pasen correctamente a la vista
        $response->assertViewHas('page_name', 'Slider y carrousel principal');
        $response->assertViewHas('page_title', 'Slider y carrousel principal');
        $response->assertViewHas('resources', [
            "resources/js/administration_module/carrousels.js"
        ]);
        $response->assertViewHas('coursesSlider');
        $response->assertViewHas('educationalProgramsSlider');
        $response->assertViewHas('coursesCarrousel');
        $response->assertViewHas('educationalProgramsCarrousel');
        $response->assertViewHas('submenuselected', 'carrousels');
    }

    /** @test Guardar privisualización del Slider*/
    public function testSavesASliderPrevisualization()
    {
        // Simular los datos de la solicitud
        $data = [
            'title' => 'Sample Slider',
            'description' => 'This is a sample slider description.',
            'image' => UploadedFile::fake()->image('slider-image.jpg'),
            'color' => '#8a3838',
        ];

        // Realizar la solicitud POST
        $response = $this->postJson('/sliders/save_previsualization', $data);

        // Verificar que la respuesta sea correcta
        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Se ha guardado la previsualización del slider',
            ]);

        // Verificar que el registro se haya guardado en la base de datos
        $this->assertDatabaseHas('sliders_previsualizations', [
            'title' => 'Sample Slider',
            'description' => 'This is a sample slider description.',
        ]);

        // Verificar que se haya generado un UID
        $this->assertNotNull($response->json('previsualizationUid'));
    }

    /**
     * @test Error 422 Imagen no existe     *
     */
    public function testException422WhenImageIsNotProvided()
    {
        $educationalProgram = EducationalProgramsModel::factory()->withEducationalProgramType()->create([
            'uid' => generate_uuid(),
            'featured_slider_image_path' => '',
        ])->first();

        // Simula los datos que normalmente vendrían en la solicitud
        $data = [
            'title' => 'Sample Slider',
            'description' => 'This is a sample slider description.',
            'color' => '#8a3838',
            'learning_object_type' => 'educational_program',
            'educational_program_uid' => $educationalProgram->uid,
        ];
        // Realizar la solicitud POST
        $response = $this->postJson('/sliders/save_previsualization', $data);

        // Verificar que se lanza una excepción con el mensaje correcto
        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Debes adjuntar una imagen',
            ]);

    }

    /** @test Guardar Carrusel Grande Aprobado  */
    public function testSavesBigCarrouselApprovals()
    {

        //   Crear registros usando el factory
         $course1 = CoursesModel::factory()->withCourseStatus()->withCourseType()->create([
            'featured_big_carrousel_approved' => false,
         ]);


        $course2 = CoursesModel::factory()->withCourseStatus()->withCourseType()->create([
            'featured_big_carrousel_approved' => true,
        ]);

        // Mockear los datos de entrada
        $courses = [
            ['uid' => $course1->uid, 'checked' => true],
            ['uid' => $course2->uid, 'checked' => false],
        ];

        $education1 = EducationalProgramsModel::factory()->withEducationalProgramType()->create([
            'featured_slider_approved' => false,
        ]);

        $education2 = EducationalProgramsModel::factory()->withEducationalProgramType()->create([
            'featured_slider_approved' => true,
        ]);

        $educationalPrograms = [
            ['uid' => $education1->uid, 'checked' => true],
            ['uid' => $education2->uid, 'checked' => false],
        ];

        // Mockear la autenticación
        $user = UsersModel::factory()->create();
        Auth::shouldReceive('user')->andReturn($user);

        // Enviar petición a la ruta
        $response = $this->post('/administration/carrousels/save_big_carrousels_approvals', [
            'courses' => $courses,
            'educationalPrograms' => $educationalPrograms,
        ]);

        // Verificar la respuesta
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Se han actualizado los cursos a mostrar en el carrousel grande'
        ]);

        // Verificar los cambios en la base de datos
        $this->assertDatabaseHas('courses', [
            'uid' => $course1->uid,
            'featured_big_carrousel_approved' => true,
        ]);

        $this->assertDatabaseHas('courses', [
            'uid' => $course2->uid,
            'featured_big_carrousel_approved' => false,
        ]);

        $this->assertDatabaseHas('educational_programs', [
            'uid' => $education1->uid,
            'featured_slider_approved' => true,
        ]);

        $this->assertDatabaseHas('educational_programs', [
            'uid' => $education2->uid,
            'featured_slider_approved' => false,
        ]);

    }

    /** @test */
    // Esto no está hecho validar que se envia la data con 422 cuando este vacio
    // public function TestFailsValidationWhenDataIsMissingBigCarrouselApprovals()
    // {
    //     // Enviar datos incompletos
    //     $data = [
    //         'courses' => [], // Sin cursos
    //         'educationalPrograms' => [], // Sin programas educativos
    //     ];

    //     $response = $this->postJson('/administration/carrousels/save_big_carrousels_approvals', $data);

    //     // Verificar que la respuesta sea un error de validación
    //     $response->assertStatus(422)
    //         ->assertJsonValidationErrors(['courses', 'educationalPrograms']);
    // }

     /** @test Guardar Carrusel Grande Aprobado  */
     public function testSavesSmallCarrouselApprovals()
     {

         //   Crear registros usando el factory
          $course1 = CoursesModel::factory()->withCourseStatus()->withCourseType()->create([
             'featured_small_carrousel_approved' => false,
          ]);


         $course2 = CoursesModel::factory()->withCourseStatus()->withCourseType()->create([
             'featured_small_carrousel_approved' => true,
         ]);

         // Mockear los datos de entrada
         $courses = [
             ['uid' => $course1->uid, 'checked' => true],
             ['uid' => $course2->uid, 'checked' => false],
         ];

         $education1 = EducationalProgramsModel::factory()->withEducationalProgramType()->create([
             'featured_main_carrousel_approved' => false,
         ]);

         $education2 = EducationalProgramsModel::factory()->withEducationalProgramType()->create([
             'featured_main_carrousel_approved' => true,
         ]);

         $educationalPrograms = [
             ['uid' => $education1->uid, 'checked' => true],
             ['uid' => $education2->uid, 'checked' => false],
         ];

         // Mockear la autenticación
         $user = UsersModel::factory()->create();
         Auth::shouldReceive('user')->andReturn($user);

         // Enviar petición a la ruta
         $response = $this->post('/administration/carrousels/save_small_carrousels_approvals', [
             'courses' => $courses,
             'educationalPrograms' => $educationalPrograms,
         ]);

         // Verificar la respuesta
         $response->assertStatus(200);
         $response->assertJson([
             'status' => 'success',
             'message' => 'Se han actualizado los cursos a mostrar en el carrousel pequeño'
         ]);

         // Verificar los cambios en la base de datos
         $this->assertDatabaseHas('courses', [
             'uid' => $course1->uid,
             'featured_small_carrousel_approved' => true,
         ]);

         $this->assertDatabaseHas('courses', [
             'uid' => $course2->uid,
             'featured_small_carrousel_approved' => false,
         ]);

         $this->assertDatabaseHas('educational_programs', [
             'uid' => $education1->uid,
             'featured_main_carrousel_approved' => true,
         ]);

         $this->assertDatabaseHas('educational_programs', [
             'uid' => $education2->uid,
             'featured_main_carrousel_approved' => false,
         ]);

     }


     /** @test Actualiza carrusel cuando pertenece a un Programa Educacional*/
    public function testUpdatesCarouselFieldsBelongsToEducationalProgram()
    {
        // Simulamos un curso
        $course_bd = Mockery::mock(CoursesModel::class)->makePartial();

        // Simulamos el request
        $request = new Request([
            'belongs_to_educational_program' => true,
        ]);

        // Crear mocks del certificado
        $certidigitalServiceMock = $this->createMock(CertidigitalService::class);

        // Create a mock for EmbeddingsService

        $mockEmbeddingsService = $this->createMock(EmbeddingsService::class);

        // Instantiate ManagementCoursesController with the mocked service
        $controller = new ManagementCoursesController($mockEmbeddingsService, $certidigitalServiceMock);

        // Usamos reflexión para acceder al método privado
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('updateCarrouselFields');
        $method->setAccessible(true);

        // Llamamos al método privado
        $method->invokeArgs($controller, [$request, $course_bd]);

        // Verificamos que los atributos del curso se hayan restablecido
        $this->assertNull($course_bd->featured_big_carrousel_title);
        $this->assertNull($course_bd->featured_big_carrousel_description);
        $this->assertNull($course_bd->featured_big_carrousel_image_path);
        $this->assertNull($course_bd->featured_big_carrousel);
        $this->assertNull($course_bd->featured_small_carrousel);
    }

    /** @test Actualiza Carrusel cuando no esta presente Programas Eduacuionales*/
    public function testUpdatesBigCarouselFieldsNotEducationalProgram()
    {
        // Simulamos un curso
        $course_bd = Mockery::mock(CoursesModel::class)->makePartial();

        // Simulamos el request
        $request = new Request([
            'belongs_to_educational_program' => false,
            'featured_small_carrousel' => true,
            'featured_big_carrousel' => true,
            'featured_big_carrousel_title' => 'Title',
            'featured_big_carrousel_description' => 'Description',
            'featured_big_carrousel_image_path' => 'image.jpg',
        ]);

        // Crear mocks del certificado
        $certidigitalServiceMock = $this->createMock(CertidigitalService::class);


         // Create a mock for EmbeddingsService
         $mockEmbeddingsService = $this->createMock(EmbeddingsService::class);

        // Instantiate ManagementCoursesController with the mocked service
        $controller = new ManagementCoursesController($mockEmbeddingsService, $certidigitalServiceMock );


        // Usamos reflexión para acceder al método privado
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('updateCarrouselFields');
        $method->setAccessible(true);

        // Llamamos al método privado
        $method->invokeArgs($controller, [$request, $course_bd]);

        // Verificamos que se haya establecido el atributo correcto
        $this->assertEquals('Title', $course_bd->featured_big_carrousel_title);
        $this->assertEquals('Description', $course_bd->featured_big_carrousel_description);
        $this->assertTrue($course_bd->featured_small_carrousel);
    }

    /** @test Actualiza Carrusel cuando no esta presente Programas Eduacuionales y sin Imagen*/
    public function testResetsBigCarouselFieldsNoImage()
    {
        // Simulamos un curso
        $course_bd = Mockery::mock(CoursesModel::class)->makePartial();

        // Simulamos el request sin archivo de imagen
        $request = new Request([
            'belongs_to_educational_program' => false,
            'featured_small_carrousel' => true,
            'featured_big_carrousel' => false,
            'featured_big_carrousel_title' => null,
            'featured_big_carrousel_description' => null,
            'featured_big_carrousel_image_path' => null,
        ]);

        // Crear mocks del certificado
        $certidigitalServiceMock = $this->createMock(CertidigitalService::class);

        // Create a mock for EmbeddingsService
        $mockEmbeddingsService = $this->createMock(EmbeddingsService::class);

        // Instantiate ManagementCoursesController with the mocked service
        $controller = new ManagementCoursesController($mockEmbeddingsService, $certidigitalServiceMock );

        // Usamos reflexión para acceder al método privado
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('updateCarrouselFields');
        $method->setAccessible(true);

        // Llamamos al método privado
        $method->invokeArgs($controller, [$request, $course_bd]);

        // Verificamos que los atributos se hayan restablecido correctamente
        $this->assertNull($course_bd->featured_big_carrousel_title);
        $this->assertNull($course_bd->featured_big_carrousel_description);
        $this->assertNull($course_bd->featured_big_carrousel_image_path);
    }

    public function testShouldReturnCourseImageNotUploaded()
    {
        // Crea un curso con una imagen destacada
        $course = CoursesModel::factory()->withCourseType()->withCourseStatus()->create([
            'uid' => generate_uuid(),
            'featured_big_carrousel_image_path' => 'images/test-images/743.jpg',
        ]);

        // Simula los datos que normalmente vendrían en la solicitud
        $data = [
            'learning_object_type' => 'course',
            'course_uid' => $course->uid,
        ];

        // Crea una instancia de Request con los datos simulados
        $request = Request::create('/sliders/save_previsualization', 'POST', $data);

        // Llama al método privado usando reflexión
        $sliderController = new CarrouselsController();

        // Accede al método privado usando reflexión
        $reflection = new \ReflectionClass($sliderController);
        $method = $reflection->getMethod('getPrevisualizationImage');
        $method->setAccessible(true);

        // Llama al método y verifica el resultado
        $imagePath = $method->invoke($sliderController, $request);

        // Verifica que se haya devuelto la ruta correcta de la imagen
        $this->assertEquals('images/test-images/743.jpg', $imagePath);
    }

    /** @test */
    public function testShouldReturnProgramImageNotIsUploaded()
    {
        // Crea un programa educativo con una imagen destacada
        $educationalProgram = EducationalProgramsModel::factory()->withEducationalProgramType()->create([
            'uid' => generate_uuid(),
            'featured_slider_image_path' => 'images/test-images/743.jpg',
        ])->first();

        // Simula los datos que normalmente vendrían en la solicitud
        $data = [
            'learning_object_type' => 'educational_program',
            'educational_program_uid' => $educationalProgram->uid,
        ];

        // Crea una instancia de Request con los datos simulados
        $request = Request::create('/sliders/save_previsualization', 'POST', $data);

        // Llama al método privado usando reflexión
        $sliderController = new CarrouselsController();

        // Accede al método privado usando reflexión
        $reflection = new \ReflectionClass($sliderController);
        $method = $reflection->getMethod('getPrevisualizationImage');
        $method->setAccessible(true);

        // Llama al método y verifica el resultado
        $imagePath = $method->invoke($sliderController, $request);

        // Verifica que se haya devuelto la ruta correcta de la imagen
        $this->assertEquals('images/test-images/743.jpg', $imagePath);
    }

    /**
     * @test Error Validación por cualquier campo     *
     */
    public function testThrowsExceptionWhenTitleIsMissing()
    {

        // Crea un objeto Request con datos inválidos
        $request = new Request([
            'title' => '', // Título vacío, debería fallar
            'description' => 'Descripción válida',
            'color' => 'blue',
        ]);

        // Espera que se lance una excepción OperationFailedException
        $this->expectException(OperationFailedException::class);

        // Instancia de la clase que contiene el método a probar
        $carrouselsController = new CarrouselsController();

        // Usar reflexión para acceder al método privado
        $reflection = new \ReflectionClass($carrouselsController);
        $method = $reflection->getMethod('validatePrevisualizationSlider');
        $method->setAccessible(true); // Hacerlo accesible

        // Llamar al método privado con el request inválido
        $method->invoke($carrouselsController, $request);


    }


}