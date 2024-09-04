<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmailNotificationsAutomaticModel>
 */
class EmailNotificationsAutomaticModelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uid' => generate_uuid(),
            'sent' => false,
            'user_uid' => generate_uuid(),
            'subject' => 'Test Subject',
            'parameters' => json_encode(['key' => 'value']), // Ejemplo de parámetros
            'template' => 'notification_template',
        ];
    }
}
