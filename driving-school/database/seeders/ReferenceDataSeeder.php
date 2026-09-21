<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use App\Models\LessonTopic;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $topics = [
            ['driving_practice', 'Driving Practice', 'Tababar Wadid'],
            ['parking', 'Parking', 'Baarkin'],
            ['reverse_driving', 'Reverse Driving', 'Gadaal u Waddid'],
            ['traffic_rules', 'Traffic Rules', 'Xeerarka Taraafikada'],
            ['road_signs', 'Road Signs', 'Calaamadaha Waddooyinka'],
            ['road_practice', 'Road Practice', 'Tababar Waddo'],
            ['highway_driving', 'Highway Driving', 'Wadid Waddo Weyn'],
            ['vehicle_control', 'Vehicle Control', 'Xakameynta Gaariga'],
            ['safety', 'Safety', 'Badbaado'],
            ['final_assessment', 'Final Assessment', 'Qiimeyn Kama Dambeys ah'],
            ['other', 'Other', 'Kale'],
        ];

        foreach ($topics as $index => [$code, $name, $nameSo]) {
            LessonTopic::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'name_so' => $nameSo, 'sort_order' => $index, 'is_active' => true],
            );
        }

        $categories = [
            ['fuel', 'Fuel', 'Shidaal'],
            ['garage_service', 'Garage Service', 'Adeegga Garaashka'],
            ['vehicle_repair', 'Vehicle Repair', 'Dayactirka Gaariga'],
            ['spare_parts', 'Spare Parts', 'Qaybo Gaari'],
            ['oil_change', 'Oil Change', 'Beddelka Saliidda'],
            ['tires', 'Tires', 'Taayirro'],
            ['car_wash', 'Car Wash', 'Dhaqid Gaari'],
            ['office', 'Office', 'Xafiis'],
            ['electricity', 'Electricity', 'Koronto'],
            ['internet', 'Internet', 'Internet'],
            ['rent', 'Rent', 'Kiro'],
            ['other', 'Other', 'Kale'],
        ];

        foreach ($categories as $index => [$code, $name, $nameSo]) {
            ExpenseCategory::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'name_so' => $nameSo, 'sort_order' => $index, 'is_active' => true],
            );
        }

        $settings = [
            ['school_name', 'Alpha Driving School', 'general', 'School Name'],
            ['school_phone', '+252 61 000 0000', 'general', 'Phone'],
            ['school_email', 'info@alphadriving.example', 'general', 'Email'],
            ['school_address', 'Mogadishu, Somalia', 'general', 'Address'],
            ['currency', 'USD', 'finance', 'Currency'],
            ['currency_symbol', '$', 'finance', 'Currency Symbol'],
            ['default_training_days', '24', 'training', 'Default Training Days'],
            ['near_completion_threshold', '80', 'training', 'Near Completion Threshold'],
            ['default_locale', 'en', 'general', 'Default Language'],
            ['allow_duplicate_attendance', '0', 'training', 'Allow Duplicate Attendance'],
        ];

        foreach ($settings as [$key, $value, $group, $label]) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group, 'label' => $label]);
        }
    }
}
