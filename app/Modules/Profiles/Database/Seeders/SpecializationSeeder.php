<?php

namespace App\Modules\Profiles\Database\Seeders;

use App\Modules\Profiles\Models\Specialization;
use Illuminate\Database\Seeder;

/**
 * The fixed list of specializations. Safe to run again at any time (also in production):
 * it matches rows by `slug`, so it updates names and adds new entries without duplicates,
 * and existing ids never change.
 */
class SpecializationSeeder extends Seeder
{
    /**
     * slug => [English, Arabic, children as slug => [English, Arabic]]
     */
    private const TREE = [
        'software-engineering' => ['Software Engineering', 'هندسة البرمجيات', [
            'backend' => ['Backend Development', 'تطوير الواجهة الخلفية'],
            'frontend' => ['Frontend Development', 'تطوير الواجهة الأمامية'],
            'mobile' => ['Mobile Development', 'تطوير تطبيقات الموبايل'],
            'devops' => ['DevOps & Cloud', 'DevOps والحوسبة السحابية'],
            'qa' => ['Quality Assurance & Testing', 'ضمان الجودة والاختبار'],
            'cybersecurity' => ['Cybersecurity', 'الأمن السيبراني'],
        ]],
        'data' => ['Data', 'البيانات', [
            'data-analysis' => ['Data Analysis', 'تحليل البيانات'],
            'data-science' => ['Data Science & Machine Learning', 'علم البيانات وتعلم الآلة'],
            'data-engineering' => ['Data Engineering', 'هندسة البيانات'],
        ]],
        'design' => ['Design', 'التصميم', [
            'ui-ux' => ['UI/UX Design', 'تصميم واجهات وتجربة المستخدم'],
            'graphic-design' => ['Graphic Design', 'التصميم الجرافيكي'],
        ]],
        'product' => ['Product & Project Management', 'إدارة المنتجات والمشاريع', [
            'product-management' => ['Product Management', 'إدارة المنتجات'],
            'project-management' => ['Project Management', 'إدارة المشاريع'],
        ]],
        'marketing' => ['Marketing', 'التسويق', [
            'digital-marketing' => ['Digital Marketing', 'التسويق الرقمي'],
            'content' => ['Content & Copywriting', 'صناعة المحتوى وكتابة الإعلانات'],
        ]],
        'sales' => ['Sales & Business Development', 'المبيعات وتطوير الأعمال', []],
        'human-resources' => ['Human Resources', 'الموارد البشرية', []],
        'finance' => ['Finance & Accounting', 'المالية والمحاسبة', []],
        'customer-support' => ['Customer Support', 'خدمة العملاء', []],
    ];

    public function run(): void
    {
        foreach (self::TREE as $slug => [$nameEn, $nameAr, $children]) {
            $parent = $this->save($slug, $nameEn, $nameAr, null);

            foreach ($children as $childSlug => [$childEn, $childAr]) {
                $this->save($childSlug, $childEn, $childAr, $parent->id);
            }
        }
    }

    private function save(string $slug, string $nameEn, string $nameAr, ?string $parentId): Specialization
    {
        return Specialization::updateOrCreate(
            ['slug' => $slug],
            ['name_en' => $nameEn, 'name_ar' => $nameAr, 'parent_id' => $parentId],
        );
    }
}
