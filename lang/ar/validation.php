<?php

// Messages de validation en arabe (mêmes règles que lang/fr/validation.php).
return [
    'accepted' => 'يجب تأشير الحقل :attribute.',
    'array' => 'الحقل :attribute غير صالح.',
    'boolean' => 'الحقل :attribute غير صالح.',
    'date' => 'الحقل :attribute ليس تاريخاً صالحاً.',
    'date_format' => 'يجب أن يكون الحقل :attribute بالصيغة :format.',
    'distinct' => 'الحقل :attribute مكرر.',
    'exists' => 'الحقل :attribute غير صالح.',
    'in' => 'الحقل :attribute غير صالح.',
    'integer' => 'يجب أن يكون الحقل :attribute عدداً صحيحاً.',
    'max' => ['numeric' => 'يجب ألا يتجاوز الحقل :attribute القيمة :max.', 'string' => 'يجب ألا يتجاوز الحقل :attribute :max حرفاً.', 'array' => 'يجب ألا يحتوي الحقل :attribute على أكثر من :max عنصر.'],
    'min' => ['numeric' => 'يجب أن يكون الحقل :attribute على الأقل :min.', 'string' => 'يجب أن يحتوي الحقل :attribute على :min حرفاً على الأقل.', 'array' => 'يجب أن يحتوي الحقل :attribute على :min عنصر على الأقل.'],
    'regex' => 'صيغة الحقل :attribute غير صالحة.',
    'required' => 'الحقل :attribute إجباري.',
    'required_if' => 'الحقل :attribute إجباري عندما يكون :other هو :value.',
    'string' => 'يجب أن يكون الحقل :attribute نصاً.',

    'attributes' => [
        'username' => 'اسم المستخدم', 'password' => 'كلمة المرور', 'num_doss' => 'رقم الملف', 'nom_patient' => 'اسم المريض',
        'num_demande_clinisys' => 'رقم طلب Clinisys', 'urgence' => 'درجة الاستعجال', 'nature' => 'نوع الطلب',
        'produit' => 'المنتوج', 'nb_poches' => 'عدد الأكياس', 'diagnostic' => 'التشخيص', 'numero_recu' => 'رقم الوصل',
        'identite_ok' => 'التحقق من الهوية', 'carte_groupage' => 'بطاقة الفصيلة', 'pieces.demande' => 'الطلب الورقي + بطاقة Clinisys',
        'pieces.recu' => 'وصل الأداء', 'service_source' => 'المصلحة المرسِلة', 'service_destination' => 'المصلحة المستقبِلة',
        'etat_cts' => 'الحالة في مركز تحاقن الدم', 'conforme' => 'المطابقة', 'motif_non_conformite' => 'سبب عدم المطابقة',
        'poches' => 'الأكياس', 'poches.*.numero' => 'رقم الكيس', 'bon_depot' => 'رقم وصل الإيداع', 'poches_delivrees' => 'الأكياس المصروفة',
        'issue' => 'نتيجة نقل الدم', 'motif' => 'السبب', 'commentaire' => 'التعليق', 'pin' => 'الرمز السري', 'roles' => 'الأدوار',
        'regularisation' => 'سبب التسوية', 'paiement' => 'الأداء', 'paiement_charge' => 'على نفقة', 'paiement_ref' => 'المرجع',
    ],
    'values' => ['conforme' => ['non' => 'غير مطابق'], 'motif' => ['autre' => 'آخر']],
];
