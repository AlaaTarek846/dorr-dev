<?php

/**
 * ARB (Al Rajhi Bank) Tranportal error codes → messages, ported verbatim from
 * Jawad\app\Services\ARBErrorMessages (887 codes). Looked up by
 * Modules\Wallet\Services\Gateways\ArbGateway::translateError(); a locale other
 * than en/ar falls back to en.
 *
 * @return array<string, array{en: string, ar: string}>
 */
return [
    'IPAY0100001' => [
        'en' => 'Missing error url.',
        'ar' => 'رابط الخطأ مفقود.',
    ],
    'IPAY0100002' => [
        'en' => 'Invalid error url.',
        'ar' => 'رابط الخطأ غير صالح.',
    ],
    'IPAY0100003' => [
        'en' => 'Missing response URL.',
        'ar' => 'رابط الاستجابة مفقود.',
    ],
    'IPAY0100004' => [
        'en' => 'Invalid response URL.',
        'ar' => 'رابط الاستجابة غير صالح.',
    ],
    'IPAY0100005' => [
        'en' => 'Missing Tranportal Id.',
        'ar' => 'معرف بوابة النقل مفقود.',
    ],
    'IPAY0100006' => [
        'en' => 'Invalid tranportal id.',
        'ar' => 'معرف بوابة النقل غير صالح.',
    ],
    'IPAY0100007' => [
        'en' => 'Missing transaction data.',
        'ar' => 'بيانات المعاملة مفقودة.',
    ],
    'IPAY0100008' => [
        'en' => 'Terminal Not Enabled.',
        'ar' => 'الطرفية غير مفعلة.',
    ],
    'IPAY0100009' => [
        'en' => 'Institution not enabled.',
        'ar' => 'المؤسسة غير مفعلة.',
    ],
    'IPAY0100010' => [
        'en' => 'Institution has not enabled for the encryption process.',
        'ar' => 'لم يتم تفعيل المؤسسة لعملية التشفير.',
    ],
    'IPAY0100011' => [
        'en' => 'Merchant has not enabled for encryption process.',
        'ar' => 'التاجر غير مفعل لعملية التشفير.',
    ],
    'IPAY0100012' => [
        'en' => 'Empty terminal key.',
        'ar' => 'مفتاح الطرفية فارغ.',
    ],
    'IPAY0100013' => [
        'en' => 'Invalid transaction data.',
        'ar' => 'بيانات المعاملة غير صالحة.',
    ],
    'IPAY0100014' => [
        'en' => 'Terminal Authentication requested with invalid tranportal ID data.',
        'ar' => 'طلبت مصادقة الطرفية ببيانات معرف بوابة نقل غير صالحة.',
    ],
    'IPAY0100015' => [
        'en' => 'Invalid Tranportal Password.',
        'ar' => 'كلمة مرور بوابة النقل غير صالحة.',
    ],
    'IPAY0100016' => [
        'en' => 'Password security not enabled.',
        'ar' => 'أمان كلمة المرور غير مفعل.',
    ],
    'IPAY0100017' => [
        'en' => 'Inactive terminal.',
        'ar' => 'الطرفية غير نشطة.',
    ],
    'IPAY0100018' => [
        'en' => 'Terminal password expired.',
        'ar' => 'انتهت صلاحية كلمة مرور الطرفية.',
    ],
    'IPAY0100019' => [
        'en' => 'Invalid login attempt.',
        'ar' => 'محاولة تسجيل دخول غير صالحة.',
    ],
    'IPAY0100020' => [
        'en' => 'Invalid Action type.',
        'ar' => 'نوع الإجراء غير صالح.',
    ],
    'IPAY0100021' => [
        'en' => 'Missing currency.',
        'ar' => 'العملة مفقودة.',
    ],
    'IPAY0100022' => [
        'en' => 'Invalid currency.',
        'ar' => 'العملة غير صالحة.',
    ],
    'IPAY0100023' => [
        'en' => 'Missing amount.',
        'ar' => 'المبلغ مفقود.',
    ],
    'IPAY0100024' => [
        'en' => 'Invalid Transaction Amount.',
        'ar' => 'مبلغ المعاملة غير صالح.',
    ],
    'IPAY0100025' => [
        'en' => 'Invalid amount or currency.',
        'ar' => 'المبلغ أو العملة غير صالحة.',
    ],
    'IPAY0100026' => [
        'en' => 'Invalid language id.',
        'ar' => 'معرف اللغة غير صالح.',
    ],
    'IPAY0100027' => [
        'en' => 'Invalid track id.',
        'ar' => 'معرف التتبع غير صالح.',
    ],
    'IPAY0100028' => [
        'en' => 'Invalid user defined field1.',
        'ar' => 'حقل معرف المستخدم 1 غير صالح.',
    ],
    'IPAY0100029' => [
        'en' => 'Invalid user defined field2.',
        'ar' => 'حقل معرف المستخدم 2 غير صالح.',
    ],
    'IPAY0100030' => [
        'en' => 'Invalid user defined field3.',
        'ar' => 'حقل معرف المستخدم 3 غير صالح.',
    ],
    'IPAY0100031' => [
        'en' => 'Invalid user defined field4.',
        'ar' => 'حقل معرف المستخدم 4 غير صالح.',
    ],
    'IPAY0100032' => [
        'en' => 'Invalid user defined field5.',
        'ar' => 'حقل معرف المستخدم 5 غير صالح.',
    ],
    'IPAY0100033' => [
        'en' => 'Terminal action not enabled.',
        'ar' => 'إجراء الطرفية غير مفعل.',
    ],
    'IPAY0100034' => [
        'en' => 'Currency code not enabled.',
        'ar' => 'رمز العملة غير مفعل.',
    ],
    'IPAY0100036' => [
        'en' => 'UDF Mismatched.',
        'ar' => 'عدم تطابق UDF.',
    ],
    'IPAY0100037' => [
        'en' => 'Payment id missing.',
        'ar' => 'معرف الدفع مفقود.',
    ],
    'IPAY0100038' => [
        'en' => 'Unable to process the request.',
        'ar' => 'غير قادر على معالجة الطلب.',
    ],
    'IPAY0100039' => [
        'en' => 'Invalid payment id.',
        'ar' => 'معرف الدفع غير صالح.',
    ],
    'IPAY0100042' => [
        'en' => 'PaymentId Expired.',
        'ar' => 'انتهت صلاحية معرف الدفع.',
    ],
    'IPAY0100043' => [
        'en' => 'Transaction denied: IP Blocked.',
        'ar' => 'تم رفض المعاملة: عنوان IP محظور.',
    ],
    'IPAY0100044' => [
        'en' => 'Problem occurred while loading payment page.',
        'ar' => 'حدثت مشكلة أثناء تحميل صفحة الدفع.',
    ],
    'IPAY0100045' => [
        'en' => 'DENIED BY RISK.',
        'ar' => 'تم الرفض بسبب المخاطر.',
    ],
    'IPAY0100049' => [
        'en' => 'Transaction declined due to exceeding OTP attempts.',
        'ar' => 'تم رفض المعاملة لتجاوز محاولات OTP.',
    ],
    'IPAY0100050' => [
        'en' => 'Invalid terminal key.',
        'ar' => 'مفتاح الطرفية غير صالح.',
    ],
    'IPAY0100051' => [
        'en' => 'Missing terminal key.',
        'ar' => 'مفتاح الطرفية مفقود.',
    ],
    'IPAY0100052' => [
        'en' => 'Problem occurred during merchant response encryption.',
        'ar' => 'حدثت مشكلة أثناء تشفير استجابة التاجر.',
    ],
    'IPAY0100053' => [
        'en' => 'Problem occurred while processing direct debit.',
        'ar' => 'حدثت مشكلة أثناء معالجة الخصم المباشر.',
    ],
    'IPAY0100054' => [
        'en' => 'Payment details not available.',
        'ar' => 'تفاصيل الدفع غير متوفرة.',
    ],
    'IPAY0100055' => [
        'en' => 'Invalid Payment Status.',
        'ar' => 'حالة الدفع غير صالحة.',
    ],
    'IPAY0100056' => [
        'en' => 'Instrument not allowed in Terminal and Brand.',
        'ar' => 'الأداة غير مسموح بها في الطرفية والعلامة التجارية.',
    ],
    'IPAY0100058' => [
        'en' => 'Transaction denied due to invalid instrument.',
        'ar' => 'تم رفض المعاملة بسبب أداة غير صالحة.',
    ],
    'IPAY0100059' => [
        'en' => 'Transaction denied due to invalid currency code.',
        'ar' => 'تم رفض المعاملة بسبب رمز عملة غير صالح.',
    ],
    'IPAY0100060' => [
        'en' => 'Transaction denied due to missing amount.',
        'ar' => 'تم رفض المعاملة بسبب مبلغ مفقود.',
    ],
    'IPAY0100063' => [
        'en' => 'Transaction denied due to invalid track ID.',
        'ar' => 'تم رفض المعاملة بسبب معرف تتبع غير صالح.',
    ],
    'IPAY0100064' => [
        'en' => 'Transaction denied due to invalid UDF1.',
        'ar' => 'تم رفض المعاملة بسبب UDF1 غير صالح.',
    ],
    'IPAY0100065' => [
        'en' => 'Transaction denied due to invalid UDF2.',
        'ar' => 'تم رفض المعاملة بسبب UDF2 غير صالح.',
    ],
    'IPAY0100066' => [
        'en' => 'Transaction denied due to invalid UDF3.',
        'ar' => 'تم رفض المعاملة بسبب UDF3 غير صالح.',
    ],
    'IPAY0100067' => [
        'en' => 'Transaction denied due to invalid UDF4.',
        'ar' => 'تم رفض المعاملة بسبب UDF4 غير صالح.',
    ],
    'IPAY0100068' => [
        'en' => 'Transaction denied due to invalid UDF5.',
        'ar' => 'تم رفض المعاملة بسبب UDF5 غير صالح.',
    ],
    'IPAY0100069' => [
        'en' => 'Missing Payment Instrument.',
        'ar' => 'أداة الدفع مفقودة.',
    ],
    'IPAY0100070' => [
        'en' => 'Transaction denied due to failed card check digit calculation.',
        'ar' => 'تم رفض المعاملة لفشل حساب رقم التحقق من البطاقة.',
    ],
    'IPAY0100071' => [
        'en' => 'Transaction denied due to missing CVD2.',
        'ar' => 'تم رفض المعاملة لافتقاد CVD2.',
    ],
    'IPAY0100072' => [
        'en' => 'Transaction denied due to invalid CVD2.',
        'ar' => 'تم رفض المعاملة بسبب CVD2 غير صالح.',
    ],
    'IPAY0100073' => [
        'en' => 'Transaction denied due to invalid CVV.',
        'ar' => 'تم رفض المعاملة بسبب CVV غير صالح.',
    ],
    'IPAY0100074' => [
        'en' => 'Missing Expiry Year.',
        'ar' => 'سنة الانتهاء مفقودة.',
    ],
    'IPAY0100075' => [
        'en' => 'Transaction denied due to invalid expiry year.',
        'ar' => 'تم رفض المعاملة بسبب سنة انتهاء غير صالحة.',
    ],
    'IPAY0100076' => [
        'en' => 'Missing Expiry Month.',
        'ar' => 'شهر الانتهاء مفقود.',
    ],
    'IPAY0100077' => [
        'en' => 'Transaction denied due to invalid expiry month.',
        'ar' => 'تم رفض المعاملة بسبب شهر انتهاء غير صالح.',
    ],
    'IPAY0100078' => [
        'en' => 'Transaction denied due to missing expiry day.',
        'ar' => 'تم رفض المعاملة لافتقاد يوم الانتهاء.',
    ],
    'IPAY0100079' => [
        'en' => 'Transaction denied due to invalid expiry day.',
        'ar' => 'تم رفض المعاملة بسبب يوم انتهاء غير صالح.',
    ],
    'IPAY0100080' => [
        'en' => 'Transaction denied due to expiration date.',
        'ar' => 'تم رفض المعاملة بسبب تاريخ الانتهاء.',
    ],
    'IPAY0100081' => [
        'en' => 'Card holder name is not present.',
        'ar' => 'اسم حامل البطاقة غير موجود.',
    ],
    'IPAY0100082' => [
        'en' => 'Card address is not present.',
        'ar' => 'عنوان البطاقة غير موجود.',
    ],
    'IPAY0100083' => [
        'en' => 'Card postal code is not present.',
        'ar' => 'الرمز البريدي للبطاقة غير موجود.',
    ],
    'IPAY0100084' => [
        'en' => 'AVS Check: Fail.',
        'ar' => 'فحص AVS: فشل.',
    ],
    'IPAY0100085' => [
        'en' => 'Electronic Commerce Indicator is invalid.',
        'ar' => 'مؤشر التجارة الإلكترونية غير صالح.',
    ],
    'IPAY0100086' => [
        'en' => 'Transaction denied due to missing CVV.',
        'ar' => 'تم رفض المعاملة لافتقاد CVV.',
    ],
    'IPAY0100087' => [
        'en' => 'Card pin number is not present.',
        'ar' => 'رقم التعريف الشخصي للبطاقة غير موجود.',
    ],
    'IPAY0100088' => [
        'en' => 'Empty mobile number.',
        'ar' => 'رقم الجوال فارغ.',
    ],
    'IPAY0100089' => [
        'en' => 'Invalid mobile number.',
        'ar' => 'رقم الجوال غير صالح.',
    ],
    'IPAY0100090' => [
        'en' => 'Empty MMID.',
        'ar' => 'MMID فارغ.',
    ],
    'IPAY0100091' => [
        'en' => 'Invalid MMID.',
        'ar' => 'MMID غير صالح.',
    ],
    'IPAY0100092' => [
        'en' => 'Empty OTP number.',
        'ar' => 'رقم OTP فارغ.',
    ],
    'IPAY0100093' => [
        'en' => 'Invalid OTP number.',
        'ar' => 'رقم OTP غير صالح.',
    ],
    'IPAY0100094' => [
        'en' => 'Sorry, this instrument is not handled.',
        'ar' => 'عذراً، هذه الأداة غير مدعومة.',
    ],
    'IPAY0100095' => [
        'en' => 'Terminal inactive.',
        'ar' => 'الطرفية غير نشطة.',
    ],
    'IPAY0100096' => [
        'en' => 'IMPS for Institution Not Active',
        'ar' => 'IMPS للمؤسسة غير نشط.',
    ],
    'IPAY0100097' => [
        'en' => 'IMPS for Terminal Not Active',
        'ar' => 'IMPS للطرفية غير نشط.',
    ],
    'IPAY0100100' => [
        'en' => 'Problem occurred while authorization.',
        'ar' => 'حدثت مشكلة أثناء التفويض.',
    ],
    'IPAY0100101' => [
        'en' => 'Denied by risk : Risk Profile does not exist.',
        'ar' => 'مرفوض من قبل المخاطر: ملف المخاطر غير موجود.',
    ],
    'IPAY0100102' => [
        'en' => 'Denied by risk: Maximum Floor Limit Check.',
        'ar' => 'مرفوض من قبل المخاطر: تجاوز الحد الأقصى.',
    ],
    'IPAY0100103' => [
        'en' => 'Transaction denied due to Risk : Maximum transaction count.',
        'ar' => 'تم رفض المعاملة بسبب المخاطر: تجاوز الحد الأقصى لعدد المعاملات.',
    ],
    'IPAY0100106' => [
        'en' => 'Invalid Payment Instrument.',
        'ar' => 'أداة الدفع غير صالحة.',
    ],
    'IPAY0100107' => [
        'en' => 'Instrument not enabled.',
        'ar' => 'الأداة غير مفعلة.',
    ],
    'IPAY0100108' => [
        'en' => 'Perform risk check:Failed.',
        'ar' => 'إجراء فحص المخاطر: فشل.',
    ],
    'IPAY0100109' => [
        'en' => 'Invalid subsequent transaction.',
        'ar' => 'معاملة لاحقة غير صالحة.',
    ],
    'IPAY0100110' => [
        'en' => 'Invalid subsequent transaction, Tran Ref id is null or empty.',
        'ar' => 'معاملة لاحقة غير صالحة، الرقم المرجعي للمعاملة فارغ أو مفقود.',
    ],
    'IPAY0100111' => [
        'en' => 'Card decryption failed.',
        'ar' => 'فشل فك تشفير البطاقة.',
    ],
    'IPAY0100112' => [
        'en' => 'Problem occurred in method load original transaction data.',
        'ar' => 'حدثت مشكلة في تحميل بيانات المعاملة الأصلية.',
    ],
    'IPAY0100113' => [
        'en' => 'Problem occurred in method loading original transaction data.',
        'ar' => 'حدثت مشكلة في تحميل بيانات المعاملة الأصلية.',
    ],
    'IPAY0100114' => [
        'en' => 'Duplicate Record.',
        'ar' => 'سجل مكرر.',
    ],
    'IPAY0100115' => [
        'en' => 'Transaction denied due to missing original transaction id.',
        'ar' => 'تم رفض المعاملة لافتقاد معرف المعاملة الأصلية.',
    ],
    'IPAY0100116' => [
        'en' => 'Transaction denied due to invalid original transaction id.',
        'ar' => 'تم رفض المعاملة لمعرف معاملة أصلية غير صالح.',
    ],
    'IPAY0100117' => [
        'en' => 'Transaction denied due to missing card number.',
        'ar' => 'تم رفض المعاملة لافتقاد رقم البطاقة.',
    ],
    'IPAY0100118' => [
        'en' => 'Transaction denied due to card number length error.',
        'ar' => 'تم رفض المعاملة لخطأ في طول رقم البطاقة.',
    ],
    'IPAY0100119' => [
        'en' => 'Transaction denied due to invalid card number.',
        'ar' => 'تم رفض المعاملة لرقم بطاقة غير صالح.',
    ],
    'IPAY0100121' => [
        'en' => 'Transaction denied due to invalid card holder name.',
        'ar' => 'تم رفض المعاملة لاسم حامل بطاقة غير صالح.',
    ],
    'IPAY0100122' => [
        'en' => 'Transaction denied due to invalid address.',
        'ar' => 'تم رفض المعاملة لعنوان غير صالح.',
    ],
    'IPAY0100123' => [
        'en' => 'Transaction denied due to invalid postal code.',
        'ar' => 'تم رفض المعاملة لرمز بريدي غير صالح.',
    ],
    'IPAY0100124' => [
        'en' => 'Problem occurred while validating transaction data.',
        'ar' => 'حدثت مشكلة أثناء التحقق من بيانات المعاملة.',
    ],
    'IPAY0100125' => [
        'en' => 'Payment instrument not enabled.',
        'ar' => 'أداة الدفع غير مفعلة.',
    ],
    'IPAY0100126' => [
        'en' => 'Brand not enabled.',
        'ar' => 'العلامة التجارية غير مفعلة.',
    ],
    'IPAY0100127' => [
        'en' => 'Problem occurred while doing validate original transaction.',
        'ar' => 'حدثت مشكلة أثناء التحقق من المعاملة الأصلية.',
    ],
    'IPAY0100128' => [
        'en' => 'Transaction denied due to Institution ID mismatch.',
        'ar' => 'تم رفض المعاملة لعدم تطابق معرف المؤسسة.',
    ],
    'IPAY0100129' => [
        'en' => 'Transaction denied due to Merchant ID mismatch.',
        'ar' => 'تم رفض المعاملة لعدم تطابق معرف التاجر.',
    ],
    'IPAY0100130' => [
        'en' => 'Transaction denied due to Terminal ID mismatch.',
        'ar' => 'تم رفض المعاملة لعدم تطابق معرف الطرفية.',
    ],
    'IPAY0100131' => [
        'en' => 'Transaction denied due to Payment Instrument mismatch.',
        'ar' => 'تم رفض المعاملة لعدم تطابق أداة الدفع.',
    ],
    'IPAY0100132' => [
        'en' => 'Transaction denied due to Currency Code mismatch.',
        'ar' => 'تم رفض المعاملة لعدم تطابق رمز العملة.',
    ],
    'IPAY0100133' => [
        'en' => 'Transaction denied due to Card Number mismatch.',
        'ar' => 'تم رفض المعاملة لعدم تطابق رقم البطاقة.',
    ],
    'IPAY0100134' => [
        'en' => 'Transaction denied due to invalid Result Code.',
        'ar' => 'تم رفض المعاملة لرمز نتيجة غير صالح.',
    ],
    'IPAY0100135' => [
        'en' => 'Problem occurred while doing perform action code reference id.',
        'ar' => 'حدثت مشكلة أثناء تنفيذ معرف مرجع كود الإجراء.',
    ],
    'IPAY0100136' => [
        'en' => 'Transaction denied due to previous capture check failure.',
        'ar' => 'تم رفض المعاملة لفشل فحص الالتقاط السابق.',
    ],
    'IPAY0100139' => [
        'en' => 'Transaction denied due to void amount versus original amount check failure.',
        'ar' => 'تم رفض المعاملة لفشل فحص مبلغ الإلغاء مقابل المبلغ الأصلي.',
    ],
    'IPAY0100140' => [
        'en' => 'Transaction denied due to previous void check failure.',
        'ar' => 'تم رفض المعاملة لفشل فحص الإلغاء السابق.',
    ],
    'IPAY0100141' => [
        'en' => 'Transaction denied due to purchase already credited.',
        'ar' => 'تم رفض المعاملة لأن الشراء تم قيده بالفعل.',
    ],
    'IPAY0100142' => [
        'en' => 'Problem occurred while validating original transaction.',
        'ar' => 'حدثت مشكلة أثناء التحقق من المعاملة الأصلية.',
    ],
    'IPAY0100144' => [
        'en' => 'ISO MSG is null.',
        'ar' => 'رسالة ISO فارغة.',
    ],
    'IPAY0100145' => [
        'en' => 'Problem occurred while loading default messages in ISO Formatter.',
        'ar' => 'حدثت مشكلة أثناء تحميل الرسائل الافتراضية في منسق ISO.',
    ],
    'IPAY0100146' => [
        'en' => 'Problem occurred while encrypting PIN.',
        'ar' => 'حدثت مشكلة أثناء تشفير الرقم السري.',
    ],
    'IPAY0100147' => [
        'en' => 'Problem occurred while formatting purchase request in B24 ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الشراء في منسق رسائل B24 ISO.',
    ],
    'IPAY0100148' => [
        'en' => 'Problem occurred while hashing ecom pin.',
        'ar' => 'حدثت مشكلة أثناء تخزين الرقم السري للتجارة الإلكترونية.',
    ],
    'IPAY0100149' => [
        'en' => 'Invalid PIN Type.',
        'ar' => 'نوع الرقم السري غير صالح.',
    ],
    'IPAY0100150' => [
        'en' => 'Problem occurred while formatting Reverse purchase request in B24 ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس الشراء في منسق رسائل B24 ISO.',
    ],
    'IPAY0100151' => [
        'en' => 'Problem occurred while formatting Credit request in B24 ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الائتمان في منسق رسائل B24 ISO.',
    ],
    'IPAY0100152' => [
        'en' => 'Problem occurred while formatting authorization request in B24 ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب التفويض في منسق رسائل B24 ISO.',
    ],
    'IPAY0100153' => [
        'en' => 'Problem occurred while formatting Capture request in B24 ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الالتقاط في منسق رسائل B24 ISO.',
    ],
    'IPAY0100154' => [
        'en' => 'Problem occurred while formatting Reverse Credit request in B24 ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس الائتمان في منسق رسائل B24 ISO.',
    ],
    'IPAY0100155' => [
        'en' => 'Problem occurred while formatting reverse authorization request in B24 ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس التفويض في منسق رسائل B24 ISO.',
    ],
    'IPAY0100156' => [
        'en' => 'Problem occurred while formatting Reverse Capture request in B24 ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس الالتقاط في منسق رسائل B24 ISO.',
    ],
    'IPAY0100157' => [
        'en' => 'Problem occurred while formatting vpas capture request in B24 ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب التقاط vpas في منسق رسائل B24 ISO.',
    ],
    'IPAY0100159' => [
        'en' => 'External message system error.',
        'ar' => 'خطأ في نظام الرسائل الخارجي.',
    ],
    'IPAY0100160' => [
        'en' => 'Unable to process the transaction.',
        'ar' => 'غير قادر على معالجة المعاملة.',
    ],
    'IPAY0100162' => [
        'en' => 'Merchant is not allowed for encryption process.',
        'ar' => 'التاجر غير مسموح له بعملية التشفير.',
    ],
    'IPAY0100163' => [
        'en' => 'Problem occurred during transaction.',
        'ar' => 'حدثت مشكلة أثناء المعاملة.',
    ],
    'IPAY0100164' => [
        'en' => 'Invalid ECI Value.',
        'ar' => 'قيمة ECI غير صالحة.',
    ],
    'IPAY0100166' => [
        'en' => 'Transaction Not Processed.',
        'ar' => 'لم تتم معالجة المعاملة.',
    ],
    'IPAY0100167' => [
        'en' => 'Invalid Authentication value.',
        'ar' => 'قيمة المصادقة غير صالحة.',
    ],
    'IPAY0100169' => [
        'en' => 'Invalid enrollment value.',
        'ar' => 'قيمة التسجيل غير صالحة.',
    ],
    'IPAY0100170' => [
        'en' => 'Invalid cavv value.',
        'ar' => 'قيمة cavv غير صالحة.',
    ],
    'IPAY0100176' => [
        'en' => 'Decrypting transaction data failed.',
        'ar' => 'فشل فك تشفير بيانات المعاملة.',
    ],
    'IPAY0100178' => [
        'en' => 'Merchant encryption enabled.',
        'ar' => 'تشفير التاجر مفعل.',
    ],
    'IPAY0100179' => [
        'en' => 'IVR not enabled.',
        'ar' => 'IVR غير مفعل.',
    ],
    'IPAY0100180' => [
        'en' => 'Authentication Not Available.',
        'ar' => 'المصادقة غير متوفرة.',
    ],
    'IPAY0100181' => [
        'en' => 'Card encryption failed.',
        'ar' => 'فشل تشفير البطاقة.',
    ],
    'IPAY0100182' => [
        'en' => 'Vpas merchant not enabled.',
        'ar' => 'تاجر Vpas غير مفعل.',
    ],
    'IPAY0100183' => [
        'en' => 'Error occurred Due to bytePAReq is null.',
        'ar' => 'حدث خطأ لأن bytePAReq فارغ.',
    ],
    'IPAY0100184' => [
        'en' => 'Error occurred while Parsing PAReq.',
        'ar' => 'حدث خطأ أثناء تحليل PAReq.',
    ],
    'IPAY0100185' => [
        'en' => 'Problem occurred while authentication.',
        'ar' => 'حدثت مشكلة أثناء المصادقة.',
    ],
    'IPAY0100186' => [
        'en' => 'Encryption enabled.',
        'ar' => 'التشفير مفعل.',
    ],
    'IPAY0100187' => [
        'en' => 'Customer ID is missing for Faster Checkout.',
        'ar' => 'معرف العميل مفقود للدفع السريع.',
    ],
    'IPAY0100188' => [
        'en' => 'Transaction Mode(FC) is missing for Faster Checkout.',
        'ar' => 'وضع المعاملة (FC) مفقود للدفع السريع.',
    ],
    'IPAY0100189' => [
        'en' => 'Transaction denied due to brand directory unavailable.',
        'ar' => 'تم رفض المعاملة لعدم توفر دليل العلامة التجارية.',
    ],
    'IPAY0100190' => [
        'en' => 'Transaction denied due to Risk.',
        'ar' => 'تم رفض المعاملة بسبب المخاطر.',
    ],
    'IPAY0100191' => [
        'en' => 'Denied by risk: Negative Card check.',
        'ar' => 'مرفوض من قبل المخاطر: فحص البطاقة السلبي.',
    ],
    'IPAY0100193' => [
        'en' => 'Invalid xid value.',
        'ar' => 'قيمة xid غير صالحة.',
    ],
    'IPAY0100194' => [
        'en' => 'Transaction denied due to Risk: Minimum Transaction Amount processing.',
        'ar' => 'تم رفض المعاملة بسبب المخاطر: معالجة الحد الأدنى لمبلغ المعاملة.',
    ],
    'IPAY0100195' => [
        'en' => 'Transaction denied due to Risk : Maximum credit processing amount.',
        'ar' => 'تم رفض المعاملة بسبب المخاطر: الحد الأقصى لمبلغ معالجة الائتمان.',
    ],
    'IPAY0100196' => [
        'en' => 'Transaction denied due to Risk : Maximum processing amount.',
        'ar' => 'تم رفض المعاملة بسبب المخاطر: الحد الأقصى لمبلغ المعالجة.',
    ],
    'IPAY0100197' => [
        'en' => 'Transaction denied due to Risk : Maximum debit amount.',
        'ar' => 'تم رفض المعاملة بسبب المخاطر: الحد الأقصى لمبلغ الخصم.',
    ],
    'IPAY0100198' => [
        'en' => 'Transaction denied due to Risk : Transaction count limit exceeded for the IP.',
        'ar' => 'تم رفض المعاملة بسبب المخاطر: تجاوز حد عدد المعاملات لعنوان IP.',
    ],
    'IPAY0100199' => [
        'en' => 'Transaction denied due to previous credit check failure.',
        'ar' => 'تم رفض المعاملة لفشل فحص الائتمان السابق.',
    ],
    'IPAY0100200' => [
        'en' => 'Denied by risk : Negative BIN check.',
        'ar' => 'مرفوض من قبل المخاطر: فحص BIN السلبي.',
    ],
    'IPAY0100201' => [
        'en' => 'Denied by risk : Declined Card check.',
        'ar' => 'مرفوض من قبل المخاطر: فحص البطاقة المرفوضة.',
    ],
    'IPAY0100202' => [
        'en' => 'Error occurred in Determine Payment Instrument.',
        'ar' => 'حدث خطأ في تحديد أداة الدفع.',
    ],
    'IPAY0100203' => [
        'en' => 'Problem occurred while doing perform transaction.',
        'ar' => 'حدثت مشكلة أثناء إجراء المعاملة.',
    ],
    'IPAY0100204' => [
        'en' => 'Missing payment details.',
        'ar' => 'تفاصيل الدفع مفقودة.',
    ],
    'IPAY0100205' => [
        'en' => 'Problem occurred while getting PARES details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل PARES.',
    ],
    'IPAY0100206' => [
        'en' => 'Problem occurred while getting currency minor digits.',
        'ar' => 'حدثت مشكلة أثناء الحصول على الأرقام الفرعية للعملة.',
    ],
    'IPAY0100207' => [
        'en' => 'Bin range not enabled.',
        'ar' => 'نطاق BIN غير مفعل.',
    ],
    'IPAY0100208' => [
        'en' => 'Action not enabled.',
        'ar' => 'الإجراء غير مفعل.',
    ],
    'IPAY0100209' => [
        'en' => 'Institution config not enabled.',
        'ar' => 'تكوين المؤسسة غير مفعل.',
    ],
    'IPAY0100210' => [
        'en' => 'Problem occurred during veres process.',
        'ar' => 'حدثت مشكلة أثناء عملية veres.',
    ],
    'IPAY0100211' => [
        'en' => 'Problem occurred during pareq process.',
        'ar' => 'حدثت مشكلة أثناء عملية pareq.',
    ],
    'IPAY0100212' => [
        'en' => 'Problem occurred while getting veres.',
        'ar' => 'حدثت مشكلة أثناء الحصول على veres.',
    ],
    'IPAY0100213' => [
        'en' => 'Problem occurred while processing the hosted transaction request.',
        'ar' => 'حدثت مشكلة أثناء معالجة طلب المعاملة المستضافة.',
    ],
    'IPAY0100214' => [
        'en' => 'Problem occurred while verifying tranportal password.',
        'ar' => 'حدثت مشكلة أثناء التحقق من كلمة مرور بوابة النقل.',
    ],
    'IPAY0100216' => [
        'en' => 'Invalid data received.',
        'ar' => 'تم استلام بيانات غير صالحة.',
    ],
    'IPAY0100217' => [
        'en' => 'Invalid payment detail.',
        'ar' => 'تفاصيل الدفع غير صالحة.',
    ],
    'IPAY0100218' => [
        'en' => 'Invalid brand id.',
        'ar' => 'معرف العلامة التجارية غير صالح.',
    ],
    'IPAY0100219' => [
        'en' => 'Missing Card Number.',
        'ar' => 'رقم البطاقة مفقود.',
    ],
    'IPAY0100220' => [
        'en' => 'Invalid Card Number.',
        'ar' => 'رقم البطاقة غير صالح.',
    ],
    'IPAY0100221' => [
        'en' => 'Missing card holder name.',
        'ar' => 'اسم حامل البطاقة مفقود.',
    ],
    'IPAY0100222' => [
        'en' => 'Invalid zip code.',
        'ar' => 'الرمز البريدي غير صالح.',
    ],
    'IPAY0100223' => [
        'en' => 'Missing cvv.',
        'ar' => 'CVV مفقود.',
    ],
    'IPAY0100224' => [
        'en' => 'Invalid cvv.',
        'ar' => 'CVV غير صالح.',
    ],
    'IPAY0100225' => [
        'en' => 'Missing card expiry year.',
        'ar' => 'سنة انتهاء البطاقة مفقودة.',
    ],
    'IPAY0100226' => [
        'en' => 'Invalid card expiry year.',
        'ar' => 'سنة انتهاء البطاقة غير صالحة.',
    ],
    'IPAY0100227' => [
        'en' => 'Missing card expiry month.',
        'ar' => 'شهر انتهاء البطاقة مفقود.',
    ],
    'IPAY0100228' => [
        'en' => 'Invalid card expiry month.',
        'ar' => 'شهر انتهاء البطاقة غير صالح.',
    ],
    'IPAY0100229' => [
        'en' => 'Invalid card expiry day.',
        'ar' => 'يوم انتهاء البطاقة غير صالح.',
    ],
    'IPAY0100230' => [
        'en' => 'Card expired.',
        'ar' => 'انتهت صلاحية البطاقة.',
    ],
    'IPAY0100231' => [
        'en' => 'Invalid user defined field.',
        'ar' => 'حقل معرف المستخدم غير صالح.',
    ],
    'IPAY0100232' => [
        'en' => 'Missing original transaction id.',
        'ar' => 'معرف المعاملة الأصلية مفقود.',
    ],
    'IPAY0100233' => [
        'en' => 'Invalid original transaction id.',
        'ar' => 'معرف المعاملة الأصلية غير صالح.',
    ],
    'IPAY0100234' => [
        'en' => 'Problem occurred while formatting Reverse Capture request in VISA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس الالتقاط في منسق رسائل VISA ISO.',
    ],
    'IPAY0100235' => [
        'en' => 'Problem occurred while formatting reverse authorization request in VISA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس التفويض في منسق رسائل VISA ISO.',
    ],
    'IPAY0100236' => [
        'en' => 'Problem occurred while formatting Reverse Credit request in VISA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس الائتمان في منسق رسائل VISA ISO.',
    ],
    'IPAY0100237' => [
        'en' => 'Problem occurred while formatting Reverse purchase request in VISA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس الشراء في منسق رسائل VISA ISO.',
    ],
    'IPAY0100238' => [
        'en' => 'Problem occurred while formatting Capture request in VISA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الالتقاط في منسق رسائل VISA ISO.',
    ],
    'IPAY0100239' => [
        'en' => 'Problem occurred while formatting authorization request in VISA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب التفويض في منسق رسائل VISA ISO.',
    ],
    'IPAY0100240' => [
        'en' => 'Problem occurred while formatting Credit request in VISA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الائتمان في منسق رسائل VISA ISO.',
    ],
    'IPAY0100241' => [
        'en' => 'Problem occurred while formatting purchase request in VISA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الشراء في منسق رسائل VISA ISO.',
    ],
    'IPAY0100242' => [
        'en' => 'RC_UNAVAILABLE.',
        'ar' => 'RC غير متوفر.',
    ],
    'IPAY0100243' => [
        'en' => 'NOT SUPPORTED.',
        'ar' => 'غير مدعوم.',
    ],
    'IPAY0100244' => [
        'en' => 'Payment Instrument Not Configured.',
        'ar' => 'أداة الدفع غير مهيأة.',
    ],
    'IPAY0100245' => [
        'en' => 'Problem occurred while sending/receivinig ISO message.',
        'ar' => 'حدثت مشكلة أثناء إرسال/استقبال رسالة ISO.',
    ],
    'IPAY0100246' => [
        'en' => 'Problem occurred while doing perform ip risk check.',
        'ar' => 'حدثت مشكلة أثناء إجراء فحص مخاطر IP.',
    ],
    'IPAY0100247' => [
        'en' => 'PARES message format is invalid.',
        'ar' => 'تنسيق رسالة PARES غير صالح.',
    ],
    'IPAY0100248' => [
        'en' => 'Problem occurred while validating PARES message format.',
        'ar' => 'حدثت مشكلة أثناء التحقق من تنسيق رسالة PARES.',
    ],
    'IPAY0100249' => [
        'en' => 'Merchant response url is down.',
        'ar' => 'رابط استجابة التاجر معطل.',
    ],
    'IPAY0100250' => [
        'en' => 'Payment details verification failed.',
        'ar' => 'فشل التحقق من تفاصيل الدفع.',
    ],
    'IPAY0100251' => [
        'en' => 'Invalid payment data.',
        'ar' => 'بيانات الدفع غير صالحة.',
    ],
    'IPAY0100252' => [
        'en' => 'Missing veres.',
        'ar' => 'veres مفقود.',
    ],
    'IPAY0100253' => [
        'en' => 'Problem occurred while cancelling the transaction.',
        'ar' => 'حدثت مشكلة أثناء إلغاء المعاملة.',
    ],
    'IPAY0100254' => [
        'en' => 'Merchant not enabled for performing transaction.',
        'ar' => 'التاجر غير مفعل لإجراء المعاملة.',
    ],
    'IPAY0100255' => [
        'en' => 'External connection not enabled.',
        'ar' => 'الاتصال الخارجي غير مفعل.',
    ],
    'IPAY0100256' => [
        'en' => 'Payment encryption failed.',
        'ar' => 'فشل تشفير الدفع.',
    ],
    'IPAY0100257' => [
        'en' => 'Brand rules not enabled.',
        'ar' => 'قواعد العلامة التجارية غير مفعلة.',
    ],
    'IPAY0100258' => [
        'en' => 'Certification verification failed.',
        'ar' => 'فشل التحقق من الشهادة.',
    ],
    'IPAY0100259' => [
        'en' => 'Problem occurred during merchant hashing process.',
        'ar' => 'حدثت مشكلة أثناء عملية التجزئة للتاجر.',
    ],
    'IPAY0100260' => [
        'en' => 'Payment option(s) not enabled.',
        'ar' => 'خيارات الدفع غير مفعلة.',
    ],
    'IPAY0100261' => [
        'en' => 'Payment hashing failed.',
        'ar' => 'فشل عملية التجزئة للدفع.',
    ],
    'IPAY0100262' => [
        'en' => 'Problem occurred during VEREQ process.',
        'ar' => 'حدثت مشكلة أثناء عملية VEREQ.',
    ],
    'IPAY0100263' => [
        'en' => 'Transaction not found.',
        'ar' => 'المعاملة غير موجودة.',
    ],
    'IPAY0100264' => [
        'en' => 'Signature validation failed.',
        'ar' => 'فشل التحقق من التوقيع.',
    ],
    'IPAY0100265' => [
        'en' => 'PARes status not sucessful.',
        'ar' => 'حالة PARes ليست ناجحة.',
    ],
    'IPAY0100266' => [
        'en' => 'Brand directory unavailable.',
        'ar' => 'دليل العلامة التجارية غير متوفر.',
    ],
    'IPAY0100268' => [
        'en' => '3d secure not enabled for the brand.',
        'ar' => '3D Secure غير مفعل للعلامة التجارية.',
    ],
    'IPAY0100269' => [
        'en' => 'Invalid card check digit.',
        'ar' => 'رقم التحقق من البطاقة غير صالح.',
    ],
    'IPAY0100271' => [
        'en' => 'Problem occurred while formatting purchase request in MASTER ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الشراء في منسق رسائل MASTER ISO.',
    ],
    'IPAY0100272' => [
        'en' => 'Problem occurred while validating xml message format.',
        'ar' => 'حدثت مشكلة أثناء التحقق من تنسيق رسالة XML.',
    ],
    'IPAY0100273' => [
        'en' => 'Problem occurred while validation VERES message format.',
        'ar' => 'حدثت مشكلة أثناء التحقق من تنسيق رسالة VERES.',
    ],
    'IPAY0100274' => [
        'en' => 'VERES message format is invalid.',
        'ar' => 'تنسيق رسالة VERES غير صالح.',
    ],
    'IPAY0100275' => [
        'en' => 'Problem occurred while formatting Credit request in MASTER ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الائتمان في منسق رسائل MASTER ISO.',
    ],
    'IPAY0100276' => [
        'en' => 'Problem occurred while formatting Reverse purchase request in MASTER ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس الشراء في منسق رسائل MASTER ISO.',
    ],
    'IPAY0100277' => [
        'en' => 'Problem occurred while formatting Reverse Credit request in MASTER ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس الائتمان في منسق رسائل MASTER ISO.',
    ],
    'IPAY0100278' => [
        'en' => 'Problem occurred while formatting reverse authorization request in MASTER ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس التفويض في منسق رسائل MASTER ISO.',
    ],
    'IPAY0100279' => [
        'en' => 'Problem occurred while formatting Reverse Capture request in MASTER ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس الالتقاط في منسق رسائل MASTER ISO.',
    ],
    'IPAY0100280' => [
        'en' => 'Problem occurred while formatting Capture request in MASTER ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الالتقاط في منسق رسائل MASTER ISO.',
    ],
    'IPAY0100281' => [
        'en' => 'Transaction Denied due to missing Master Brand.',
        'ar' => 'تم رفض المعاملة لافتقاد علامة Master التجارية.',
    ],
    'IPAY0100282' => [
        'en' => 'Transaction Denied due to missing Visa Brand.',
        'ar' => 'تم رفض المعاملة لافتقاد علامة Visa التجارية.',
    ],
    'IPAY0100283' => [
        'en' => 'Problem occurred in determine payment instrument.',
        'ar' => 'حدثت مشكلة في تحديد أداة الدفع.',
    ],
    'IPAY0100284' => [
        'en' => 'Invalid subsequent transaction, track id is null or empty.',
        'ar' => 'معاملة لاحقة غير صالحة، معرف التتبع فارغ أو مفقود.',
    ],
    'IPAY0100285' => [
        'en' => 'Transaction denied due to invalid original transaction.',
        'ar' => 'تم رفض المعاملة لمعاملة أصلية غير صالحة.',
    ],
    'IPAY0100289' => [
        'en' => 'Transaction denied due to Risk : Maximum credit amount.',
        'ar' => 'تم رفض المعاملة بسبب المخاطر: الحد الأقصى لمبلغ الائتمان.',
    ],
    'IPAY0100291' => [
        'en' => 'Original Transaction ID should not be empty.',
        'ar' => 'يجب ألا يكون معرف المعاملة الأصلية فارغًا.',
    ],
    'IPAY0100292' => [
        'en' => 'Transaction denied due to invalid PIN.',
        'ar' => 'تم رفض المعاملة لرقم سري غير صالح.',
    ],
    'IPAY0100293' => [
        'en' => 'Transaction denied due to duplicate Merchant trackid.',
        'ar' => 'تم رفض المعاملة لتكرار معرف تتبع التاجر.',
    ],
    'IPAY0100294' => [
        'en' => 'Transaction denied due to missing Merchant trackid.',
        'ar' => 'تم رفض المعاملة لافتقاد معرف تتبع التاجر.',
    ],
    'IPAY0100295' => [
        'en' => 'Missing Merchant Track Id.',
        'ar' => 'معرف تتبع التاجر مفقود.',
    ],
    'IPAY0100296' => [
        'en' => 'Problem occurred while formatting purchase request in AMEX ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الشراء في منسق رسائل AMEX ISO.',
    ],
    'IPAY0100297' => [
        'en' => 'Problem occurred while formatting Credit request in AMEX ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الائتمان في منسق رسائل AMEX ISO.',
    ],
    'IPAY0100298' => [
        'en' => 'Problem occurred while formatting Reversal request in AMEX ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب العكس في منسق رسائل AMEX ISO.',
    ],
    'IPAY0100299' => [
        'en' => 'Problem occurred while inserting AAV details.',
        'ar' => 'حدثت مشكلة أثناء إدراج تفاصيل AAV.',
    ],
    'IPAY0100300' => [
        'en' => 'Transaction denied due to invalid ship-to first name.',
        'ar' => 'تم رفض المعاملة لاسم مستلم الشحن الأول غير صالح.',
    ],
    'IPAY0100301' => [
        'en' => 'Transaction denied due to invalid ship-to last name.',
        'ar' => 'تم رفض المعاملة لاسم مستلم الشحن الأخير غير صالح.',
    ],
    'IPAY0100302' => [
        'en' => 'Transaction denied due to invalid ship-to address.',
        'ar' => 'تم رفض المعاملة لعنوان شحن غير صالح.',
    ],
    'IPAY0100303' => [
        'en' => 'Transaction denied due to invalid ship-to Zip code.',
        'ar' => 'تم رفض المعاملة لرمز بريدي للشحن غير صالح.',
    ],
    'IPAY0100304' => [
        'en' => 'Transaction denied due to invalid ship-to Mobile Number.',
        'ar' => 'تم رفض المعاملة لرقم جوال الشحن غير صالح.',
    ],
    'IPAY0100305' => [
        'en' => 'Transaction denied due to invalid customer email.',
        'ar' => 'تم رفض المعاملة لبريد إلكتروني للعميل غير صالح.',
    ],
    'IPAY0100306' => [
        'en' => 'Transaction denied due to invalid country code.',
        'ar' => 'تم رفض المعاملة لرمز دولة غير صالح.',
    ],
    'IPAY0100307' => [
        'en' => 'Transaction denied due to invalid card first name.',
        'ar' => 'تم رفض المعاملة لاسم أول للبطاقة غير صالح.',
    ],
    'IPAY0100308' => [
        'en' => 'Transaction denied due to invalid card last name.',
        'ar' => 'تم رفض المعاملة لاسم أخير للبطاقة غير صالح.',
    ],
    'IPAY0100310' => [
        'en' => 'Transaction denied due to invalid Zip code.',
        'ar' => 'تم رفض المعاملة لرمز بريدي غير صالح.',
    ],
    'IPAY0100311' => [
        'en' => 'Transaction denied due to invalid Mobile Number.',
        'ar' => 'تم رفض المعاملة لرقم جوال غير صالح.',
    ],
    'IPAY0100312' => [
        'en' => 'Problem occurred while getting AMEX header details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل رأس AMEX.',
    ],
    'IPAY0100313' => [
        'en' => 'AMEX header details are not available.',
        'ar' => 'تفاصيل رأس AMEX غير متوفرة.',
    ],
    'IPAY0100314' => [
        'en' => 'Problem occurred while getting AAV details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل AAV.',
    ],
    'IPAY0100315' => [
        'en' => 'AAV details are not available.',
        'ar' => 'تفاصيل AAV غير متوفرة.',
    ],
    'IPAY0100316' => [
        'en' => 'Problem occurred while checking AAV details.',
        'ar' => 'حدثت مشكلة أثناء التحقق من تفاصيل AAV.',
    ],
    'IPAY0100317' => [
        'en' => 'Problem occurred while updating AAV details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل AAV.',
    ],
    'IPAY0100318' => [
        'en' => 'Problem occurred while getting tranlog extn details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل امتداد سجل المعاملات.',
    ],
    'IPAY0100319' => [
        'en' => 'Tranlog Extn details are not available.',
        'ar' => 'تفاصيل امتداد سجل المعاملات غير متوفرة.',
    ],
    'IPAY0100320' => [
        'en' => 'Problem occurred while inserting Tranlog Extn details.',
        'ar' => 'حدثت مشكلة أثناء إدراج تفاصيل امتداد سجل المعاملات.',
    ],
    'IPAY0100321' => [
        'en' => 'Card First Name and Last Name are missing.',
        'ar' => 'الاسم الأول والأخير للبطاقة مفقودان.',
    ],
    'IPAY0100322' => [
        'en' => 'Invalid CSC/CID length.',
        'ar' => 'طول CSC/CID غير صالح.',
    ],
    'IPAY0100323' => [
        'en' => 'Invalid CSC/CID.',
        'ar' => 'CSC/CID غير صالح.',
    ],
    'IPAY0100324' => [
        'en' => 'Missing CSC/CID.',
        'ar' => 'CSC/CID مفقود.',
    ],
    'IPAY0100325' => [
        'en' => 'Transaction denied due to missing CSC/CID.',
        'ar' => 'تم رفض المعاملة لافتقاد CSC/CID.',
    ],
    'IPAY0100326' => [
        'en' => 'Transaction denied due to invalid CSC/CID.',
        'ar' => 'تم رفض المعاملة لسوء CSC/CID.',
    ],
    'IPAY0100327' => [
        'en' => 'Invalid Buyer Email ID.',
        'ar' => 'بريد إلكتروني للمشتري غير صالح.',
    ],
    'IPAY0100328' => [
        'en' => 'Invalid Buyer Mobile No.',
        'ar' => 'رقم جوال للمشتري غير صالح.',
    ],
    'IPAY0100329' => [
        'en' => 'Missing Buyer Name.',
        'ar' => 'اسم المشتري مفقود.',
    ],
    'IPAY0100330' => [
        'en' => 'Invalid Minor digits length.',
        'ar' => 'طول الأرقام الفرعية غير صالح.',
    ],
    'IPAY0100331' => [
        'en' => 'Invalid Expiry Date.',
        'ar' => 'تاريخ الانتهاء غير صالح.',
    ],
    'IPAY0100332' => [
        'en' => 'Invalid Invoice Id.',
        'ar' => 'معرف الفاتورة غير صالح.',
    ],
    'IPAY0100333' => [
        'en' => 'Invalid Item Description.',
        'ar' => 'وصف العنصر غير صالح.',
    ],
    'IPAY0100334' => [
        'en' => 'Invalid Udf1.',
        'ar' => 'Udf1 غير صالح.',
    ],
    'IPAY0100335' => [
        'en' => 'Duplicate Invoice ID',
        'ar' => 'معرف الفاتورة مكرر',
    ],
    'IPAY0100340' => [
        'en' => 'Problem occurred while adding AREQ details.',
        'ar' => 'حدثت مشكلة أثناء إضافة تفاصيل AREQ.',
    ],
    'IPAY0100341' => [
        'en' => 'Problem occurred while getting EMV2LOG details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل EMV2LOG.',
    ],
    'IPAY0100342' => [
        'en' => 'Problem occurred while updating ARES details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل ARES.',
    ],
    'IPAY0100343' => [
        'en' => 'Problem occurred while updating RREQ details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل RREQ.',
    ],
    'IPAY0100344' => [
        'en' => 'Problem occurred while updating RRES details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل RRES.',
    ],
    'IPAY0100345' => [
        'en' => 'Problem occurred while updating CRES details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل CRES.',
    ],
    'IPAY0100346' => [
        'en' => 'Problem occurred while deleting cardrange details.',
        'ar' => 'حدثت مشكلة أثناء حذف تفاصيل نطاق البطاقة.',
    ],
    'IPAY0100347' => [
        'en' => 'Problem occurred while connecting webserver.',
        'ar' => 'حدثت مشكلة أثناء الاتصال بخادم الويب.',
    ],
    'IPAY0100348' => [
        'en' => 'Problem occurred while doing Authentication.',
        'ar' => 'حدثت مشكلة أثناء إجراء المصادقة.',
    ],
    'IPAY0100349' => [
        'en' => 'Authentication Response validation failed.',
        'ar' => 'فشل التحقق من استجابة المصادقة.',
    ],
    'IPAY0100350' => [
        'en' => 'Results Request Message validation failed.',
        'ar' => 'فشل التحقق من رسالة طلب النتائج.',
    ],
    'IPAY0100351' => [
        'en' => 'Problem occurred while getting card range count details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل عدد نطاق البطاقة.',
    ],
    'IPAY0100352' => [
        'en' => 'Authentication failed.',
        'ar' => 'فشلت المصادقة.',
    ],
    'IPAY0100353' => [
        'en' => 'Card Number not found in a participating Card Range.',
        'ar' => 'رقم البطاقة غير موجود في نطاق البطاقات المشاركة.',
    ],
    'IPAY0100354' => [
        'en' => 'Invalid or Bad POST.',
        'ar' => 'POST غير صالح أو تالف.',
    ],
    'IPAY0100355' => [
        'en' => 'Missing Callback URL.',
        'ar' => 'رابط الاستدعاء مفقود.',
    ],
    'IPAY0100356' => [
        'en' => 'Signature mismatch.',
        'ar' => 'عدم تطابق التوقيع.',
    ],
    'IPAY0100357' => [
        'en' => 'NOT AUTHENTICATED.',
        'ar' => 'غير مصادق عليه.',
    ],
    'IPAY0100358' => [
        'en' => 'Shopify authorization failed.',
        'ar' => 'فشل تفويض Shopify.',
    ],
    'IPAY0100359' => [
        'en' => 'Shopify Reference ID Missing.',
        'ar' => 'معرف مرجع Shopify مفقود.',
    ],
    'IPAY0100360' => [
        'en' => 'Shopify Test Path is Not Enabled.',
        'ar' => 'مسار اختبار Shopify غير مفعل.',
    ],
    'IPAY0100361' => [
        'en' => 'Shopify Base24 Connectivity is Not Enabled.',
        'ar' => 'اتصال Shopify Base24 غير مفعل.',
    ],
    'IPAY0100362' => [
        'en' => 'Invalid payout data.',
        'ar' => 'بيانات الدفع غير صالحة.',
    ],
    'IPAY0100363' => [
        'en' => 'Invalid payout amount.',
        'ar' => 'مبلغ الدفع غير صالح.',
    ],
    'IPAY0100364' => [
        'en' => 'Payout Amount Mismatched.',
        'ar' => 'عدم تطابق مبلغ الدفع.',
    ],
    'IPAY0100365' => [
        'en' => 'Problem occurred while inserting payout details.',
        'ar' => 'حدثت مشكلة أثناء إدراج تفاصيل الدفع.',
    ],
    'IPAY0100366' => [
        'en' => 'Problem occurred while getting payout details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل الدفع.',
    ],
    'IPAY0100367' => [
        'en' => 'Invalid bank identification code.',
        'ar' => 'رمز تعريف البنك غير صالح.',
    ],
    'IPAY0100368' => [
        'en' => 'Iban number is empty.',
        'ar' => 'رقم الايبان فارغ.',
    ],
    'IPAY0100369' => [
        'en' => 'Bank identification code is empty.',
        'ar' => 'رمز تعريف البنك فارغ.',
    ],
    'IPAY0100370' => [
        'en' => 'Invalid value date.',
        'ar' => 'تاريخ الاستحقاق غير صالح.',
    ],
    'IPAY0100371' => [
        'en' => 'Value date is empty.',
        'ar' => 'تاريخ الاستحقاق فارغ.',
    ],
    'IPAY0100372' => [
        'en' => 'Problem occurred while validating payout details.',
        'ar' => 'حدثت مشكلة أثناء التحقق من تفاصيل الدفع.',
    ],
    'IPAY0100373' => [
        'en' => 'Invalid Benificiary name.',
        'ar' => 'اسم المستفيد غير صالح.',
    ],
    'IPAY0100374' => [
        'en' => 'Benificiary name is empty.',
        'ar' => 'اسم المستفيد فارغ.',
    ],
    'IPAY0100375' => [
        'en' => 'Problem occurred while updating payout details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل الدفع.',
    ],
    'IPAY0100376' => [
        'en' => 'Loyalty Transaction is not enabled.',
        'ar' => 'معاملة الولاء غير مفعلة.',
    ],
    'IPAY0100377' => [
        'en' => 'Problem occuerd while performing Loayalty Transaction.',
        'ar' => 'حدثت مشكلة أثناء إجراء معاملة الولاء.',
    ],
    'IPAY0100378' => [
        'en' => 'Problem occuerd while performing Cybersource Transaction.',
        'ar' => 'حدثت مشكلة أثناء إجراء معاملة Cybersource.',
    ],
    'IPAY0100379' => [
        'en' => 'Bin value should be numeric.',
        'ar' => 'يجب أن تكون قيمة Bin رقمية.',
    ],
    'IPAY0100380' => [
        'en' => 'Bin number should be of 6 digits.',
        'ar' => 'يجب أن يتكون رقم Bin من 6 أرقام.',
    ],
    'IPAY0100381' => [
        'en' => 'Problem Occurred during BIN API check.',
        'ar' => 'حدثت مشكلة أثناء فحص BIN API.',
    ],
    'IPAY0100382' => [
        'en' => 'Technical Problem Occurred during BIN API check.',
        'ar' => 'حدثت مشكلة تقنية أثناء فحص BIN API.',
    ],
    'IPAY0100383' => [
        'en' => 'Missing Card On File Token.',
        'ar' => 'رمز البطاقة المحفوظة مفقود.',
    ],
    'IPAY0100384' => [
        'en' => 'Card On File Token should be numeric.',
        'ar' => 'يجب أن يكون رمز البطاقة المحفوظة رقميًا.',
    ],
    'IPAY0100385' => [
        'en' => 'Problem occurred while getting Card On File details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل البطاقة المحفوظة.',
    ],
    'IPAY0100386' => [
        'en' => 'Problem occurred while inserting Card On File details.',
        'ar' => 'حدثت مشكلة أثناء إدراج تفاصيل البطاقة المحفوظة.',
    ],
    'IPAY0100387' => [
        'en' => 'Card details not found for given Token and Masked card number.',
        'ar' => 'لم يتم العثور على تفاصيل البطاقة للرمز المعطى ورقم البطاقة المقنع.',
    ],
    'IPAY0100388' => [
        'en' => 'Expiry date is less than current date.',
        'ar' => 'تاريخ الانتهاء أقل من التاريخ الحالي.',
    ],
    'IPAY0100389' => [
        'en' => 'Problem occurred while validating card details.',
        'ar' => 'حدثت مشكلة أثناء التحقق من تفاصيل البطاقة.',
    ],
    'IPAY0100390' => [
        'en' => 'Missing masked card number.',
        'ar' => 'رقم البطاقة المقنع مفقود.',
    ],
    'IPAY0100391' => [
        'en' => 'Bill reference info is invalid.',
        'ar' => 'معلومات مرجع الفاتورة غير صالحة.',
    ],
    'IPAY0100392' => [
        'en' => 'Problem occurred during card on file registration.',
        'ar' => 'حدثت مشكلة أثناء تسجيل البطاقة في الملف.',
    ],
    'IPAY0100393' => [
        'en' => 'Invalid Card On File Token.',
        'ar' => 'رمز البطاقة في الملف غير صالح.',
    ],
    'IPAY0100394' => [
        'en' => 'Card number already registered.',
        'ar' => 'رقم البطاقة مسجل بالفعل.',
    ],
    'IPAY0100395' => [
        'en' => 'Agency code is empty.',
        'ar' => 'رمز الوكالة فارغ.',
    ],
    'IPAY0100396' => [
        'en' => 'Agency code is invalid.',
        'ar' => 'رمز الوكالة غير صالح.',
    ],
    'IPAY0100397' => [
        'en' => 'Agency code length is invalid.',
        'ar' => 'طول رمز الوكالة غير صالح.',
    ],
    'IPAY0100398' => [
        'en' => 'Problem occurred while getting cybersource configuration details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل تكوين cybersource.',
    ],
    'IPAY0100399' => [
        'en' => 'Signature mismatched.',
        'ar' => 'عدم تطابق التوقيع.',
    ],
    'IPAY0100400' => [
        'en' => 'Signature empty.',
        'ar' => 'التوقيع فارغ.',
    ],
    'IPAY0100401' => [
        'en' => 'Problem occurred while inserting agency details in mof.',
        'ar' => 'حدثت مشكلة أثناء إدراج تفاصيل الوكالة في وزارة المالية.',
    ],
    'IPAY0100402' => [
        'en' => 'Amount mismatched.',
        'ar' => 'عدم تطابق المبلغ.',
    ],
    'IPAY0100403' => [
        'en' => 'Mada reversal not supported.',
        'ar' => 'عكس مدى غير مدعوم.',
    ],
    'IPAY0100404' => [
        'en' => 'Transaction Type is invalid.',
        'ar' => 'نوع المعاملة غير صالح.',
    ],
    'IPAY0100405' => [
        'en' => 'Transaction Type length is invalid.',
        'ar' => 'طول نوع المعاملة غير صالح.',
    ],
    'IPAY0100406' => [
        'en' => 'Transaction Type is empty.',
        'ar' => 'نوع المعاملة فارغ.',
    ],
    'IPAY0100407' => [
        'en' => 'Biller ID is invalid.',
        'ar' => 'معرف الفوترة غير صالح.',
    ],
    'IPAY0100408' => [
        'en' => 'Biller ID length is invalid.',
        'ar' => 'طول معرف الفوترة غير صالح.',
    ],
    'IPAY0100409' => [
        'en' => 'Biller ID is empty.',
        'ar' => 'معرف الفوترة فارغ.',
    ],
    'IPAY0100410' => [
        'en' => 'Invalid Billpay details.',
        'ar' => 'تفاصيل دفع الفواتير غير صالحة.',
    ],
    'IPAY0100411' => [
        'en' => 'Bill Amount is empty.',
        'ar' => 'مبلغ الفاتورة فارغ.',
    ],
    'IPAY0100412' => [
        'en' => 'Bill Amount is invalid.',
        'ar' => 'مبلغ الفاتورة غير صالح.',
    ],
    'IPAY0100413' => [
        'en' => 'Bill Type is invalid.',
        'ar' => 'نوع الفاتورة غير صالح.',
    ],
    'IPAY0100414' => [
        'en' => 'Bill Type length is invalid.',
        'ar' => 'طول نوع الفاتورة غير صالح.',
    ],
    'IPAY0100415' => [
        'en' => 'Bill Type is empty.',
        'ar' => 'نوع الفاتورة فارغ.',
    ],
    'IPAY0100416' => [
        'en' => 'Problem occurred while inserting Billpay details.',
        'ar' => 'حدثت مشكلة أثناء إدراج تفاصيل دفع الفواتير.',
    ],
    'IPAY0100417' => [
        'en' => 'Bill Description length is invalid.',
        'ar' => 'طول وصف الفاتورة غير صالح.',
    ],
    'IPAY0100418' => [
        'en' => 'Bill Number is invalid.',
        'ar' => 'رقم الفاتورة غير صالح.',
    ],
    'IPAY0100419' => [
        'en' => 'Bill Number length is invalid.',
        'ar' => 'طول رقم الفاتورة غير صالح.',
    ],
    'IPAY0100420' => [
        'en' => 'Bill Number is empty.',
        'ar' => 'رقم الفاتورة فارغ.',
    ],
    'IPAY0100421' => [
        'en' => 'Bill Name is invalid.',
        'ar' => 'اسم الفاتورة غير صالح.',
    ],
    'IPAY0100422' => [
        'en' => 'ID Type is invalid.',
        'ar' => 'نوع المعرف غير صالح.',
    ],
    'IPAY0100423' => [
        'en' => 'ID Number length is invalid.',
        'ar' => 'طول رقم المعرف غير صالح.',
    ],
    'IPAY0100424' => [
        'en' => 'ID Number is empty.',
        'ar' => 'رقم المعرف فارغ.',
    ],
    'IPAY0100425' => [
        'en' => 'Problem occurred while performing Reverse Redemtion transaction.',
        'ar' => 'حدثت مشكلة أثناء إجراء معاملة عكس الاسترداد.',
    ],
    'IPAY0100426' => [
        'en' => 'Time exceeded, transaction cannot be reversed.',
        'ar' => 'تجاوز الوقت، لا يمكن عكس المعاملة.',
    ],
    'IPAY0100427' => [
        'en' => 'Invalid Payload Received.',
        'ar' => 'تم استلام حمولة غير صالحة.',
    ],
    'IPAY0100502' => [
        'en' => 'Missing Buyer Email ID.',
        'ar' => 'بريد إلكتروني للمشتري مفقود.',
    ],
    'IPAY0100504' => [
        'en' => 'Problem occurred while framing credit instalment request.',
        'ar' => 'حدثت مشكلة أثناء صياغة طلب تقسيط الائتمان.',
    ],
    'IPAY0100505' => [
        'en' => 'VISA payment option is not enabled for this merchant.',
        'ar' => 'خيار الدفع بفيزا غير مفعل لهذا التاجر.',
    ],
    'IPAY0100506' => [
        'en' => 'MASTER payment option is not enabled for this merchant.',
        'ar' => 'خيار الدفع بماستر كارد غير مفعل لهذا التاجر.',
    ],
    'IPAY0100507' => [
        'en' => 'MADA payment option is not enabled for this merchant.',
        'ar' => 'خيار الدفع بمدى غير مفعل لهذا التاجر.',
    ],
    'IPAY0100508' => [
        'en' => 'UDF5 length should not be greater than 255.',
        'ar' => 'يجب ألا يزيد طول UDF5 عن 255.',
    ],
    'IPAY0100509' => [
        'en' => 'UDF6 length should not be greater than 255.',
        'ar' => 'يجب ألا يزيد طول UDF6 عن 255.',
    ],
    'IPAY0100511' => [
        'en' => 'UDF8 length should not be greater than 255.',
        'ar' => 'يجب ألا يزيد طول UDF8 عن 255.',
    ],
    'IPAY0100512' => [
        'en' => 'UDF9 length should not be greater than 255.',
        'ar' => 'يجب ألا يزيد طول UDF9 عن 255.',
    ],
    'IPAY0100513' => [
        'en' => 'UDF10 length should not be greater than 255.',
        'ar' => 'يجب ألا يزيد طول UDF10 عن 255.',
    ],
    'IPAY0100514' => [
        'en' => 'UDF4 length should not be greater than 255.',
        'ar' => 'يجب ألا يزيد طول UDF4 عن 255.',
    ],
    'IPAY0100515' => [
        'en' => 'UDF3 length should not be greater than 255.',
        'ar' => 'يجب ألا يزيد طول UDF3 عن 255.',
    ],
    'IPAY0100516' => [
        'en' => 'UDF2 length should not be greater than 255.',
        'ar' => 'يجب ألا يزيد طول UDF2 عن 255.',
    ],
    'IPAY0100517' => [
        'en' => 'UDF1 length should not be greater than 255.',
        'ar' => 'يجب ألا يزيد طول UDF1 عن 255.',
    ],
    'IPAY0100518' => [
        'en' => 'Problem occurred during card on file deregistration.',
        'ar' => 'حدثت مشكلة أثناء إلغاء تسجيل البطاقة في الملف.',
    ],
    'IPAY0100519' => [
        'en' => 'Invalid cvd2.',
        'ar' => 'cvd2 غير صالح.',
    ],
    'IPAY0100521' => [
        'en' => 'Missing Bin Number.',
        'ar' => 'رقم Bin مفقود.',
    ],
    'IPAY0100522' => [
        'en' => 'Issuer Agency Id is empty.',
        'ar' => 'معرف وكالة الإصدار فارغ.',
    ],
    'IPAY0100523' => [
        'en' => 'Problem occurred while framing Request for webhook.',
        'ar' => 'حدثت مشكلة أثناء صياغة طلب webhook.',
    ],
    'IPAY0100524' => [
        'en' => 'Missing Buyer Mobile No.',
        'ar' => 'رقم جوال المشتري مفقود.',
    ],
    'IPAY0100525' => [
        'en' => 'Invalid card holder First name length.',
        'ar' => 'طول الاسم الأول لحامل البطاقة غير صالح.',
    ],
    'IPAY0100526' => [
        'en' => 'Invalid card holder Last name length.',
        'ar' => 'طول الاسم الأخير لحامل البطاقة غير صالح.',
    ],
    'IPAY0100527' => [
        'en' => 'Invalid card holder Last name.',
        'ar' => 'الاسم الأخير لحامل البطاقة غير صالح.',
    ],
    'IPAY0100528' => [
        'en' => 'Invalid card holder name.',
        'ar' => 'اسم حامل البطاقة غير صالح.',
    ],
    'IPAY0100529' => [
        'en' => 'Invalid card holder First name.',
        'ar' => 'الاسم الأول لحامل البطاقة غير صالح.',
    ],
    'IPAY0100530' => [
        'en' => 'Issuer Agency Id is invalid.',
        'ar' => 'معرف وكالة الإصدار غير صالح.',
    ],
    'IPAY0100531' => [
        'en' => 'Issuer Agency Id length is invalid.',
        'ar' => 'طول معرف وكالة الإصدار غير صالح.',
    ],
    'IPAY0100532' => [
        'en' => 'Billing Account Id is empty.',
        'ar' => 'معرف حساب الفوترة فارغ.',
    ],
    'IPAY0100533' => [
        'en' => 'Billing Account Id is invalid.',
        'ar' => 'معرف حساب الفوترة غير صالح.',
    ],
    'IPAY0100534' => [
        'en' => 'Billing Account Id length is invalid.',
        'ar' => 'طول معرف حساب الفوترة غير صالح.',
    ],
    'IPAY0100535' => [
        'en' => 'Billing Cycle is invalid.',
        'ar' => 'دورة الفوترة غير صالحة.',
    ],
    'IPAY0100536' => [
        'en' => 'Billing Cycle length is invalid.',
        'ar' => 'طول دورة الفوترة غير صالح.',
    ],
    'IPAY0100537' => [
        'en' => 'Due amount is empty.',
        'ar' => 'المبلغ المستحق فارغ.',
    ],
    'IPAY0100538' => [
        'en' => 'Due amount is invalid.',
        'ar' => 'المبلغ المستحق غير صالح.',
    ],
    'IPAY0100539' => [
        'en' => 'Paid amount is empty.',
        'ar' => 'المبلغ المدفوع فارغ.',
    ],
    'IPAY0100540' => [
        'en' => 'Paid amount is invalid.',
        'ar' => 'المبلغ المدفوع غير صالح.',
    ],
    'IPAY0100541' => [
        'en' => 'Bill reference info length is invalid.',
        'ar' => 'طول معلومات مرجع الفاتورة غير صالح.',
    ],
    'IPAY0100542' => [
        'en' => 'Problem occurred while getting mof details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل وزارة المالية.',
    ],
    'IPAY0100543' => [
        'en' => 'Problem occurred while framing mof info for webhook.',
        'ar' => 'حدثت مشكلة أثناء صياغة معلومات وزارة المالية لـ webhook.',
    ],
    'IPAY0100544' => [
        'en' => 'Problem occurred while updating Faster Checkout details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل الدفع السريع.',
    ],
    'IPAY0100545' => [
        'en' => 'ID Type length is invalid.',
        'ar' => 'طول نوع المعرف غير صالح.',
    ],
    'IPAY0100546' => [
        'en' => 'Transaction denied due to missing PIN.',
        'ar' => 'تم رفض المعاملة لافتقاد الرقم السري.',
    ],
    'IPAY0100547' => [
        'en' => 'Invalid Buyer Name.',
        'ar' => 'اسم المشتري غير صالح.',
    ],
    'IPAY0100548' => [
        'en' => 'Problem occurred in method tranlog insert for invoice.',
        'ar' => 'حدثت مشكلة في طريقة إدراج سجل المعاملة للفاتورة.',
    ],
    'IPAY0100550' => [
        'en' => 'ID Type is empty.',
        'ar' => 'نوع المعرف فارغ.',
    ],
    'IPAY0100551' => [
        'en' => 'Challenge Response Message validation failed.',
        'ar' => 'فشل التحقق من رسالة استجابة التحدي.',
    ],
    'IPAY0100552' => [
        'en' => 'Invalid Callback url.',
        'ar' => 'رابط الاستدعاء غير صالح.',
    ],
    'IPAY0100553' => [
        'en' => 'Problem occurred while updating Acquire Ticket details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل تذكرة الاستحواذ.',
    ],
    'IPAY0100554' => [
        'en' => 'Problem occurred while updating Credit Instalment details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل تقسيط الائتمان.',
    ],
    'IPAY0100555' => [
        'en' => 'Transaction Declined Due To Exceeding OTP Resend Attempts.',
        'ar' => 'تم رفض المعاملة لتجاوز محاولات إعادة إرسال OTP.',
    ],
    'IPAY0100556' => [
        'en' => 'Transaction denied due to authorization already captured (Validate Original Transaction).',
        'ar' => 'تم رفض المعاملة لأنه تم التقاط التفويض بالفعل (التحقق من المعاملة الأصلية).',
    ],
    'IPAY0100557' => [
        'en' => 'Problem occurred while inserting Webhook details.',
        'ar' => 'حدثت مشكلة أثناء إدراج تفاصيل Webhook.',
    ],
    'IPAY0100558' => [
        'en' => 'Invalid iban number.',
        'ar' => 'رقم الايبان غير صالح.',
    ],
    'IPAY0100559' => [
        'en' => 'Bank identification code Length should be between 8 and 12.',
        'ar' => 'يجب أن يكون طول رمز تعريف البنك بين 8 و 12.',
    ],
    'IPAY0100560' => [
        'en' => 'Iban number Length should be between 24 and 35.',
        'ar' => 'يجب أن يكون طول رقم الايبان بين 24 و 35.',
    ],
    'IPAY0100561' => [
        'en' => 'Benificiary name should be less than length of 100.',
        'ar' => 'يجب أن يكون اسم المستفيد أقل من 100 حرف.',
    ],
    'IPAY0100562' => [
        'en' => 'Invalid ECI Value in request.',
        'ar' => 'قيمة ECI غير صالحة في الطلب.',
    ],
    'IPAY0100563' => [
        'en' => 'Missing CurrencyCode.',
        'ar' => 'رمز العملة مفقود.',
    ],
    'IPAY0100565' => [
        'en' => 'ID Number is invalid.',
        'ar' => 'رقم المعرف غير صالح.',
    ],
    'IPAY0100566' => [
        'en' => 'Card Range not exists.',
        'ar' => 'نطاق البطاقة غير موجود.',
    ],
    'IPAY0100567' => [
        'en' => 'Problem occurred while processing the applePay transaction.',
        'ar' => 'حدثت مشكلة أثناء معالجة معاملة ApplePay.',
    ],
    'IPAY0100568' => [
        'en' => 'Problem occurred while getting mada key.',
        'ar' => 'حدثت مشكلة أثناء الحصول على مفتاح مدى.',
    ],
    'IPAY0100569' => [
        'en' => 'Rupay Initiate Failure.',
        'ar' => 'فشل بدء Rupay.',
    ],
    'IPAY0100570' => [
        'en' => 'Transaction denied due to session data mismatch.',
        'ar' => 'تم رفض المعاملة لعدم تطابق بيانات الجلسة.',
    ],
    'IPAY0100571' => [
        'en' => 'Invalid Expiration Date.',
        'ar' => 'تاريخ الانتهاء غير صالح.',
    ],
    'IPAY0100572' => [
        'en' => 'Problem occurred while updating payment details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل الدفع.',
    ],
    'IPAY0100573' => [
        'en' => 'Problem occuerd while validating MOF details.',
        'ar' => 'حدثت مشكلة أثناء التحقق من تفاصيل وزارة المالية.',
    ],
    'IPAY0100574' => [
        'en' => 'Problem occurred while adding transaction log details.',
        'ar' => 'حدثت مشكلة أثناء إضافة تفاصيل سجل المعاملة.',
    ],
    'IPAY0100575' => [
        'en' => 'Invalid Amount length.',
        'ar' => 'طول المبلغ غير صالح.',
    ],
    'IPAY0100576' => [
        'en' => 'Missing Transaction Amount.',
        'ar' => 'مبلغ المعاملة مفقود.',
    ],
    'IPAY0100577' => [
        'en' => 'Missing Currency Code.',
        'ar' => 'رمز العملة مفقود.',
    ],
    'IPAY0100578' => [
        'en' => 'Problem occurred while getting bin range details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل نطاق bin.',
    ],
    'IPAY0100579' => [
        'en' => 'Invalid input data received.',
        'ar' => 'تم استلام بيانات إدخال غير صالحة.',
    ],
    'IPAY0100580' => [
        'en' => 'Problem occurred while getting merchant session.',
        'ar' => 'حدثت مشكلة أثناء الحصول على جلسة التاجر.',
    ],
    'IPAY0100581' => [
        'en' => 'Transaction details not available.',
        'ar' => 'تفاصيل المعاملة غير متوفرة.',
    ],
    'IPAY0100582' => [
        'en' => 'Transaction denied due to missing expiry month.',
        'ar' => 'تم رفض المعاملة لافتقاد شهر الانتهاء.',
    ],
    'IPAY0100583' => [
        'en' => 'Transaction denied due to missing expiry year.',
        'ar' => 'تم رفض المعاملة لافتقاد سنة الانتهاء.',
    ],
    'IPAY0100584' => [
        'en' => 'Processing Direct Debit request.',
        'ar' => 'جاري معالجة طلب الخصم المباشر.',
    ],
    'IPAY0100586' => [
        'en' => 'Missing card holder Last name.',
        'ar' => 'الاسم الأخير لحامل البطاقة مفقود.',
    ],
    'IPAY0100587' => [
        'en' => 'Invalid user defined field6.',
        'ar' => 'حقل معرف المستخدم 6 غير صالح.',
    ],
    'IPAY0100588' => [
        'en' => 'Invalid user defined field7.',
        'ar' => 'حقل معرف المستخدم 7 غير صالح.',
    ],
    'IPAY0100589' => [
        'en' => 'Invalid user defined field8.',
        'ar' => 'حقل معرف المستخدم 8 غير صالح.',
    ],
    'IPAY0100590' => [
        'en' => 'Invalid user defined field9.',
        'ar' => 'حقل معرف المستخدم 9 غير صالح.',
    ],
    'IPAY0100591' => [
        'en' => 'Invalid user defined field10.',
        'ar' => 'حقل معرف المستخدم 10 غير صالح.',
    ],
    'IPAY0100592' => [
        'en' => 'Invalid zip code length.',
        'ar' => 'طول الرمز البريدي غير صالح.',
    ],
    'IPAY0100593' => [
        'en' => 'Missing email id.',
        'ar' => 'البريد الإلكتروني مفقود.',
    ],
    'IPAY0100595' => [
        'en' => 'Missing address.',
        'ar' => 'العنوان مفقود.',
    ],
    'IPAY0100596' => [
        'en' => 'Invalid mobile number length.',
        'ar' => 'طول رقم الجوال غير صالح.',
    ],
    'IPAY0100597' => [
        'en' => 'Missing card holder First name.',
        'ar' => 'الاسم الأول لحامل البطاقة مفقود.',
    ],
    'IPAY0100598' => [
        'en' => 'Missing Cardholder\'s Name.',
        'ar' => 'اسم حامل البطاقة مفقود.',
    ],
    'IPAY0100599' => [
        'en' => 'Missing mobile number.',
        'ar' => 'رقم الجوال مفقود.',
    ],
    'IPAY0100601' => [
        'en' => 'Invalid email id.',
        'ar' => 'البريد الإلكتروني غير صالح.',
    ],
    'IPAY0100602' => [
        'en' => 'Invalid address length.',
        'ar' => 'طول العنوان غير صالح.',
    ],
    'IPAY0100603' => [
        'en' => 'Invalid address.',
        'ar' => 'العنوان غير صالح.',
    ],
    'IPAY0100604' => [
        'en' => 'Invalid email id length.',
        'ar' => 'طول البريد الإلكتروني غير صالح.',
    ],
    'IPAY0100605' => [
        'en' => 'Missing zip code.',
        'ar' => 'الرمز البريدي مفقود.',
    ],
    'IPAY0200002' => [
        'en' => 'Problem occurred while getting institution details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل المؤسسة.',
    ],
    'IPAY0200003' => [
        'en' => 'Problem occurred while getting merchant details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل التاجر.',
    ],
    'IPAY0200004' => [
        'en' => 'Problem occurred while getting password security rules.',
        'ar' => 'حدثت مشكلة أثناء الحصول على قواعد أمان كلمة المرور.',
    ],
    'IPAY0200005' => [
        'en' => 'Problem occurred while updating terminal details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل الطرفية.',
    ],
    'IPAY0200007' => [
        'en' => 'Problem occurred while validating payment details.',
        'ar' => 'حدثت مشكلة أثناء التحقق من تفاصيل الدفع.',
    ],
    'IPAY0200008' => [
        'en' => 'Problem occurred while verifying payment details.',
        'ar' => 'حدثت مشكلة أثناء التأكد من تفاصيل الدفع.',
    ],
    'IPAY0200009' => [
        'en' => 'Problem occurred while getting payment details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل الدفع.',
    ],
    'IPAY0200010' => [
        'en' => 'Problem occurred while updating the details in payment log.',
        'ar' => 'حدثت مشكلة أثناء تحديث التفاصيل في سجل الدفع.',
    ],
    'IPAY0200011' => [
        'en' => 'Problem occurred while getting ipblock details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل حظر IP.',
    ],
    'IPAY0200012' => [
        'en' => 'Problem occurred while updating payment log ip details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل IP في سجل الدفع.',
    ],
    'IPAY0200013' => [
        'en' => 'Problem occurred while updating description details in payment log.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل الوصف في سجل الدفع.',
    ],
    'IPAY0200014' => [
        'en' => 'Problem occurred during merchant response.',
        'ar' => 'حدثت مشكلة أثناء استجابة التاجر.',
    ],
    'IPAY0200015' => [
        'en' => 'Problem occurred while getting terminal.',
        'ar' => 'حدثت مشكلة أثناء الحصول على الطرفية.',
    ],
    'IPAY0200016' => [
        'en' => 'Problem occurred while getting payment instrument.',
        'ar' => 'حدثت مشكلة أثناء الحصول على أداة الدفع.',
    ],
    'IPAY0200017' => [
        'en' => 'Problem occurred while getting payment instrument list.',
        'ar' => 'حدثت مشكلة أثناء الحصول على قائمة أدوات الدفع.',
    ],
    'IPAY0200018' => [
        'en' => 'Problem occurred while getting transaction details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل المعاملة.',
    ],
    'IPAY0200019' => [
        'en' => 'Problem occurred while getting risk profile details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل ملف المخاطر.',
    ],
    'IPAY0200020' => [
        'en' => 'Problem occurred while performing transaction risk check.',
        'ar' => 'حدثت مشكلة أثناء إجراء فحص مخاطر المعاملة.',
    ],
    'IPAY0200021' => [
        'en' => 'Problem occurred while performing risk check.',
        'ar' => 'حدثت مشكلة أثناء إجراء فحص المخاطر.',
    ],
    'IPAY0200023' => [
        'en' => 'Problem occurred while determining payment instrument.',
        'ar' => 'حدثت مشكلة أثناء تحديد أداة الدفع.',
    ],
    'IPAY0200024' => [
        'en' => 'Problem occurred while getting brand rules details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل قواعد العلامة التجارية.',
    ],
    'IPAY0200025' => [
        'en' => 'Problem occurred while getting terminal details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل الطرفية.',
    ],
    'IPAY0200026' => [
        'en' => 'Problem occurred while getting transaction log details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل سجل المعاملة.',
    ],
    'IPAY0200027' => [
        'en' => 'Missing encrypted card number.',
        'ar' => 'رقم البطاقة المشفر مفقود.',
    ],
    'IPAY0200028' => [
        'en' => 'Problem occurred while loading default institution configuration (Validate Original Transaction).',
        'ar' => 'حدثت مشكلة أثناء تحميل التكوين الافتراضي للمؤسسة (التحقق من المعاملة الأصلية).',
    ],
    'IPAY0200029' => [
        'en' => 'Problem occurred while getting external connection details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل الاتصال الخارجي.',
    ],
    'IPAY0200030' => [
        'en' => 'No external connection details for extr conn id:',
        'ar' => 'لا توجد تفاصيل اتصال خارجي لمعرف الاتصال:',
    ],
    'IPAY0200031' => [
        'en' => 'Alternate external connection details not found for the alt extr conn id:',
        'ar' => 'لم يتم العثور على تفاصيل الاتصال الخارجي البديل لمعرف الاتصال البديل:',
    ],
    'IPAY0200032' => [
        'en' => 'Problem occurred while getting external connection details for extr conn id:',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل الاتصال الخارجي لمعرف الاتصال:',
    ],
    'IPAY0200033' => [
        'en' => 'Problem occurred while getting vpas log details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل سجل vpas.',
    ],
    'IPAY0200034' => [
        'en' => 'Problem occurred while getting details from VPASLOG table for payment id: null',
        'ar' => 'حدثت مشكلة أثناء الحصول على التفاصيل من جدول VPASLOG لمعرف الدفع: null',
    ],
    'IPAY0200037' => [
        'en' => 'Error occurred while getting Merchant ID.',
        'ar' => 'حدث خطأ أثناء الحصول على معرف التاجر.',
    ],
    'IPAY0200038' => [
        'en' => 'Problem occurred while getting vpas merchant details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل تاجر vpas.',
    ],
    'IPAY0200039' => [
        'en' => 'Problem occurred while getting Faster Checkout details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل الدفع السريع.',
    ],
    'IPAY0200040' => [
        'en' => 'Problem occurred while performing card risk check.',
        'ar' => 'حدثت مشكلة أثناء إجراء فحص مخاطر البطاقة.',
    ],
    'IPAY0200041' => [
        'en' => 'Problem occurred while getting institution configuration.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تكوين المؤسسة.',
    ],
    'IPAY0200042' => [
        'en' => 'Problem occurred while getting brand.',
        'ar' => 'حدثت مشكلة أثناء الحصول على العلامة التجارية.',
    ],
    'IPAY0200043' => [
        'en' => 'Problem occurred while getting mada brand details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل علامة مدى التجارية.',
    ],
    'IPAY0200044' => [
        'en' => 'Mada Keys not enabled.',
        'ar' => 'مفاتيح مدى غير مفعلة.',
    ],
    'IPAY0200045' => [
        'en' => 'Problem occurred while updating VPASLOG table.',
        'ar' => 'حدثت مشكلة أثناء تحديث جدول VPASLOG.',
    ],
    'IPAY0200046' => [
        'en' => 'Unable to update VPASLOG table, payment id is null.',
        'ar' => 'تعذر تحديث جدول VPASLOG، معرف الدفع null.',
    ],
    'IPAY0200047' => [
        'en' => 'Problem occurred while getting details from VPASLOG table for payment id.',
        'ar' => 'حدثت مشكلة أثناء الحصول على التفاصيل من جدول VPASLOG لمعرف الدفع.',
    ],
    'IPAY0200048' => [
        'en' => 'Problem occurred while getting details from VPASLOG table.',
        'ar' => 'حدثت مشكلة أثناء الحصول على التفاصيل من جدول VPASLOG.',
    ],
    'IPAY0200049' => [
        'en' => 'Card number is null. Unable to update risk factors in negative card table & declined card table.',
        'ar' => 'رقم البطاقة null. تعذر تحديث عوامل المخاطرة في جدول البطاقات السلبية وجدول البطاقات المرفوضة.',
    ],
    'IPAY0200050' => [
        'en' => 'Problem occurred while updating risk in negative card details.',
        'ar' => 'حدثت مشكلة أثناء تحديث المخاطر في تفاصيل البطاقة السلبية.',
    ],
    'IPAY0200051' => [
        'en' => 'Problem occurred while updating risk in declined card table.',
        'ar' => 'حدثت مشكلة أثناء تحديث المخاطر في جدول البطاقات المرفوضة.',
    ],
    'IPAY0200052' => [
        'en' => 'Problem occurred while updating risk factor.',
        'ar' => 'حدثت مشكلة أثناء تحديث عامل المخاطرة.',
    ],
    'IPAY0200053' => [
        'en' => 'Problem occurred while updating payment log currency details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل العملة في سجل الدفع.',
    ],
    'IPAY0200054' => [
        'en' => 'Problem occurred while inserting currency conversion currency details.',
        'ar' => 'حدثت مشكلة أثناء إدراج تفاصيل تحويل العملة.',
    ],
    'IPAY0200055' => [
        'en' => 'Problem occurred while updating currency conversion currency details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل تحويل العملة.',
    ],
    'IPAY0200056' => [
        'en' => 'Problem occurred while getting brand details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل العلامة التجارية.',
    ],
    'IPAY0200057' => [
        'en' => 'Problem occurred while getting external connection details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل الاتصال الخارجي.',
    ],
    'IPAY0200058' => [
        'en' => 'Problem occurred while updating message log 2fa details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل سجل رسائل 2fa.',
    ],
    'IPAY0200059' => [
        'en' => 'Problem occurred while updating vpas details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل vpas.',
    ],
    'IPAY0200060' => [
        'en' => 'Problem occurred while adding vpas details.',
        'ar' => 'حدثت مشكلة أثناء إضافة تفاصيل vpas.',
    ],
    'IPAY0200061' => [
        'en' => 'Problem occurred during batch 2fa process.',
        'ar' => 'حدثت مشكلة أثناء عملية 2fa المجمعة.',
    ],
    'IPAY0200062' => [
        'en' => 'Problem occurred while getting brand rules details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل قواعد العلامة التجارية.',
    ],
    'IPAY0200063' => [
        'en' => 'Problem occurred while updating payment log process code details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل رمز العملية في سجل الدفع.',
    ],
    'IPAY0200064' => [
        'en' => 'Problem occurred while updating payment log process code and ip details.',
        'ar' => 'حدثت مشكلة أثناء تحديث رمز العملية وتفاصيل IP في سجل الدفع.',
    ],
    'IPAY0200065' => [
        'en' => 'Problem occurred while updating payment log description details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل الوصف في سجل الدفع.',
    ],
    'IPAY0200066' => [
        'en' => 'Problem occurred while updating payment log instrument details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل الأداة في سجل الدفع.',
    ],
    'IPAY0200067' => [
        'en' => 'Problem occurred while updating payment log udf Fields.',
        'ar' => 'حدثت مشكلة أثناء تحديث حقول udf في سجل الدفع.',
    ],
    'IPAY0200068' => [
        'en' => 'Problem occurred while validating IP address blocking.',
        'ar' => 'حدثت مشكلة أثناء التحقق من حظر عنوان IP.',
    ],
    'IPAY0200069' => [
        'en' => 'Problem occurred while updating payment log card details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل البطاقة في سجل الدفع.',
    ],
    'IPAY0200070' => [
        'en' => 'Problem occurred while updating ipblock details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل حظر IP.',
    ],
    'IPAY0200071' => [
        'en' => 'Probelm occurred during authentication.',
        'ar' => 'حدثت مشكلة أثناء المصادقة.',
    ],
    'IPAY0200072' => [
        'en' => 'Rupay Auth log details not available.',
        'ar' => 'تفاصيل سجل مصادقة Rupay غير متوفرة.',
    ],
    'IPAY0200073' => [
        'en' => 'Only Purchase and and Auth transaction allowed in Pre Auth Transaction.',
        'ar' => 'يُسمح فقط بمعاملات الشراء والتفويض في المعاملة المسبقة التفويض.',
    ],
    'IPAY0200074' => [
        'en' => 'Only Purchase Action Allowed for Dinners Card.',
        'ar' => 'يُسمح فقط بإجراء الشراء لبطاقة Diners.',
    ],
    'IPAY0200075' => [
        'en' => 'Aggregator is down.',
        'ar' => 'المجمع معطل.',
    ],
    'IPAY0200076' => [
        'en' => 'Transaction ip details not found.',
        'ar' => 'لم يتم العثور على تفاصيل IP للمعاملة.',
    ],
    'IPAY0200077' => [
        'en' => 'Payment details missing.',
        'ar' => 'تفاصيل الدفع مفقودة.',
    ],
    'IPAY0200078' => [
        'en' => 'Host is down.',
        'ar' => 'المضيف معطل.',
    ],
    'IPAY0200079' => [
        'en' => 'Problem occurred while updating payment log browser information.',
        'ar' => 'حدثت مشكلة أثناء تحديث معلومات المتصفح في سجل الدفع.',
    ],
    'IPAY0200080' => [
        'en' => 'Invalid keystore.',
        'ar' => 'keystore غير صالح.',
    ],
    'IPAY0200081' => [
        'en' => 'Unknown IMPS Tran Action Code encountered.',
        'ar' => 'تم مواجهة رمز إجراء معاملة IMPS غير معروف.',
    ],
    'IPAY0200082' => [
        'en' => 'Missing cvd2.',
        'ar' => 'cvd2 مفقود.',
    ],
    'IPAY0200083' => [
        'en' => 'Invalid vereq.',
        'ar' => 'vereq غير صالح.',
    ],
    'IPAY0200085' => [
        'en' => 'Checkbin Failure.',
        'ar' => 'فشل فحص Bin.',
    ],
    'IPAY0200092' => [
        'en' => 'Payment log details not available.',
        'ar' => 'تفاصيل سجل الدفع غير متوفرة.',
    ],
    'IPAY0200102' => [
        'en' => 'Error while processing the Order List Transactions.',
        'ar' => 'خطأ أثناء معالجة معاملات قائمة الطلبات.',
    ],
    'IPAY0200103' => [
        'en' => 'Exception in OTP process.',
        'ar' => 'استثناء في عملية OTP.',
    ],
    'IPAY0200104' => [
        'en' => 'Exception in parsing Action Code.',
        'ar' => 'استثناء في تحليل رمز الإجراء.',
    ],
    'IPAY0200105' => [
        'en' => 'Error in ECI Validation.',
        'ar' => 'خطأ في التحقق من ECI.',
    ],
    'IPAY0200106' => [
        'en' => 'Exception in validation Parameters.',
        'ar' => 'استثناء في معلمات التحقق.',
    ],
    'IPAY0200107' => [
        'en' => 'Unable to process currency conversion',
        'ar' => 'تعذر معالجة تحويل العملة.',
    ],
    'IPAY0200108' => [
        'en' => 'MultiCurrency Refunding is not allowed.',
        'ar' => 'استرداد العملات المتعددة غير مسموح به.',
    ],
    'IPAY0200109' => [
        'en' => 'Formatter instance creation failed.',
        'ar' => 'فشل إنشاء مثيل المنسق.',
    ],
    'IPAY0200110' => [
        'en' => 'Unable to process request, unsupported visa vpas action code.',
        'ar' => 'تعذر معالجة الطلب، رمز إجراء visa vpas غير مدعوم.',
    ],
    'IPAY0200111' => [
        'en' => 'Unable to process request, unsupported master vpas action code.',
        'ar' => 'تعذر معالجة الطلب، رمز إجراء master vpas غير مدعوم.',
    ],
    'IPAY0200112' => [
        'en' => 'Visa',
        'ar' => 'فيزا.',
    ],
    'IPAY0200113' => [
        'en' => 'Master',
        'ar' => 'ماستر.',
    ],
    'IPAY0200114' => [
        'en' => 'Unable to process request, unsupported VISA credit action code.',
        'ar' => 'تعذر معالجة الطلب، رمز إجراء ائتمان VISA غير مدعوم.',
    ],
    'IPAY0200115' => [
        'en' => 'Unable to process request, unsupported MASTER credit action code.',
        'ar' => 'تعذر معالجة الطلب، رمز إجراء ائتمان MASTER غير مدعوم.',
    ],
    'IPAY0200116' => [
        'en' => 'Unable to process request, unsupported debit action code.',
        'ar' => 'تعذر معالجة الطلب، رمز إجراء الخصم غير مدعوم.',
    ],
    'IPAY0200117' => [
        'en' => 'Netbanking not allowed.',
        'ar' => 'الخدمات المصرفية عبر الإنترنت غير مسموح بها.',
    ],
    'IPAY0200121' => [
        'en' => 'FSSConnect Destination is down.',
        'ar' => 'وجهة FSSConnect معطلة.',
    ],
    'IPAY0200200' => [
        'en' => 'SMS Server communication failure.',
        'ar' => 'فشل الاتصال بخادم الرسائل القصيرة.',
    ],
    'IPAY0200201' => [
        'en' => 'OTP Email Sending failed.',
        'ar' => 'فشل إرسال بريد OTP الإلكتروني.',
    ],
    'IPAY0200202' => [
        'en' => 'FAILED.',
        'ar' => 'فشلت العملية.',
    ],
    'IPAY0200203' => [
        'en' => 'Transaction denied while getting Ip Risk details.',
        'ar' => 'تم رفض المعاملة أثناء الحصول على تفاصيل مخاطر IP.',
    ],
    'IPAY0200204' => [
        'en' => 'Transaction denied while getting card risk details.',
        'ar' => 'تم رفض المعاملة أثناء الحصول على تفاصيل مخاطر البطاقة.',
    ],
    'IPAY0200205' => [
        'en' => 'Transaction denied while getting transaction risk details.',
        'ar' => 'تم رفض المعاملة أثناء الحصول على تفاصيل مخاطر المعاملة.',
    ],
    'IPAY0200206' => [
        'en' => 'Exception in PreAuth Transaction Process.',
        'ar' => 'استثناء في عملية المعاملة المسبقة التفويض.',
    ],
    'IPAY0200207' => [
        'en' => 'Transaction Failed due to in mastero validation failed for the terminal.',
        'ar' => 'فشلت المعاملة لفشل التحقق من Maestro للطرفية.',
    ],
    'IPAY0200208' => [
        'en' => 'Transaction timed out during VPAS transaction',
        'ar' => 'انتهت مهلة المعاملة أثناء معاملة VPAS.',
    ],
    'IPAY0200209' => [
        'en' => 'Unable to connect webserver for 3D secure enrollment.',
        'ar' => 'تعذر الاتصال بخادم الويب لتسجيل 3D Secure.',
    ],
    'IPAY0200210' => [
        'en' => 'Transaction denied due to error in IVR password encryption.',
        'ar' => 'تم رفض المعاملة لخطأ في تشفير كلمة مرور IVR.',
    ],
    'IPAY0200211' => [
        'en' => 'Error occurred while getting Institution ID.',
        'ar' => 'حدث خطأ أثناء الحصول على معرف المؤسسة.',
    ],
    'IPAY0200213' => [
        'en' => 'Error occurred while getting Brand ID.',
        'ar' => 'حدث خطأ أثناء الحصول على معرف العلامة التجارية.',
    ],
    'IPAY0200214' => [
        'en' => 'Error occurred while getting External Connection ID.',
        'ar' => 'حدث خطأ أثناء الحصول على معرف الاتصال الخارجي.',
    ],
    'IPAY0200215' => [
        'en' => 'Error occurred Due to XMLPAReq is null.',
        'ar' => 'حدث خطأ لأن XMLPAReq فارغ.',
    ],
    'IPAY0200216' => [
        'en' => 'Transaction detail is invalid.',
        'ar' => 'تفاصيل المعاملة غير صالحة.',
    ],
    'IPAY0200300' => [
        'en' => 'Missing transaction details.',
        'ar' => 'تفاصيل المعاملة مفقودة.',
    ],
    'IPAY0200301' => [
        'en' => 'Invalid transaction details.',
        'ar' => 'تفاصيل المعاملة غير صالحة.',
    ],
    'IPAY0300001' => [
        'en' => 'Action not supported.',
        'ar' => 'الإجراء غير مدعوم.',
    ],
    'IPAY0300002' => [
        'en' => 'Invalid pre authentication status.',
        'ar' => 'حالة المصادقة المسبقة غير صالحة.',
    ],
    'IPAY0300003' => [
        'en' => 'Invalid Card Number data.',
        'ar' => 'بيانات رقم البطاقة غير صالحة.',
    ],
    'IPAY0300004' => [
        'en' => 'Card Number Not Numeric.',
        'ar' => 'رقم البطاقة ليس رقميًا.',
    ],
    'IPAY0300005' => [
        'en' => 'Invalid Subsequent Transaction.',
        'ar' => 'معاملة لاحقة غير صالحة.',
    ],
    'IPAY0300006' => [
        'en' => 'Invalid Transaction Attempt.',
        'ar' => 'محاولة معاملة غير صالحة.',
    ],
    'IPAY0300007' => [
        'en' => 'Transaction denied due to invalid UDF6:',
        'ar' => 'تم رفض المعاملة بسبب UDF6 غير صالح:',
    ],
    'IPAY0300008' => [
        'en' => 'Transaction denied due to invalid UDF7:',
        'ar' => 'تم رفض المعاملة بسبب UDF7 غير صالح:',
    ],
    'IPAY0300009' => [
        'en' => 'Transaction denied due to invalid UDF8:',
        'ar' => 'تم رفض المعاملة بسبب UDF8 غير صالح:',
    ],
    'IPAY0300010' => [
        'en' => 'Transaction denied due to invalid UDF9:',
        'ar' => 'تم رفض المعاملة بسبب UDF9 غير صالح:',
    ],
    'IPAY0300011' => [
        'en' => 'Transaction denied due to invalid UDF10:',
        'ar' => 'تم رفض المعاملة بسبب UDF10 غير صالح:',
    ],
    'IPAY0300012' => [
        'en' => 'Transaction denied due to invalid UDF11:',
        'ar' => 'تم رفض المعاملة بسبب UDF11 غير صالح:',
    ],
    'IPAY0300013' => [
        'en' => 'Transaction denied due to invalid UDF12:',
        'ar' => 'تم رفض المعاملة بسبب UDF12 غير صالح:',
    ],
    'IPAY0300014' => [
        'en' => 'Problem occurred while fetching the Payzapp Response Code.',
        'ar' => 'حدثت مشكلة أثناء جلب رمز استجابة Payzapp.',
    ],
    'IPAY0300015' => [
        'en' => 'Payzapp Response Code not available.',
        'ar' => 'رمز استجابة Payzapp غير متوفر.',
    ],
    'IPAY0300016' => [
        'en' => 'Problem occurred while fetching the Payzapp Configuration.',
        'ar' => 'حدثت مشكلة أثناء جلب تكوين Payzapp.',
    ],
    'IPAY0300017' => [
        'en' => 'Payzapp not configured.',
        'ar' => 'لم يتم تعيين تكوين Payzapp.',
    ],
    'IPAY0300018' => [
        'en' => 'Transaction denied due to invalid UDF13:',
        'ar' => 'تم رفض المعاملة بسبب UDF13 غير صالح:',
    ],
    'IPAY0300019' => [
        'en' => 'Transaction denied due to invalid UDF14:',
        'ar' => 'تم رفض المعاملة بسبب UDF14 غير صالح:',
    ],
    'IPAY0300020' => [
        'en' => 'Transaction denied due to invalid UDF15:',
        'ar' => 'تم رفض المعاملة بسبب UDF15 غير صالح:',
    ],
    'IPAY0300021' => [
        'en' => 'Problem occurred in Payzapp Refund Response.',
        'ar' => 'حدثت مشكلة في استجابة استرداد Payzapp.',
    ],
    'IPAY0300023' => [
        'en' => 'No such terminals for this batch transaction.',
        'ar' => 'لا توجد مثل هذه المحطات لمعاملة الدفعة هذه.',
    ],
    'IPAY0300024' => [
        'en' => 'Failed credit greater than debit check.',
        'ar' => 'فشل فحص الائتمان أكبر من الخصم.',
    ],
    'IPAY0300025' => [
        'en' => 'Failed capture greater than auth check.',
        'ar' => 'فشل الالتقاط أكبر من فحص التفويض.',
    ],
    'IPAY0300026' => [
        'en' => 'Problem occurred while getting other payment details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل دفع أخرى.',
    ],
    'IPAY0300027' => [
        'en' => 'Problem occurred while getting card range details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل نطاق البطاقة.',
    ],
    'IPAY0300028' => [
        'en' => 'Problem occurred while sending response to merchant.',
        'ar' => 'حدثت مشكلة أثناء إرسال الاستجابة للتاجر.',
    ],
    'IPAY0300029' => [
        'en' => 'Problem occurred while Getting Transaction details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل المعاملة.',
    ],
    'IPAY0300030' => [
        'en' => 'Problem occurred while Inserting Transaction Details.',
        'ar' => 'حدثت مشكلة أثناء إدراج تفاصيل المعاملة.',
    ],
    'IPAY0300031' => [
        'en' => 'Problem occurred while processing Payzapp transaction.',
        'ar' => 'حدثت مشكلة أثناء معالجة معاملة Payzapp.',
    ],
    'IPAY0300032' => [
        'en' => 'Missing ENROLLED_STATUS.',
        'ar' => 'حالة التسجيل ENROLLED_STATUS مفقودة.',
    ],
    'IPAY0300033' => [
        'en' => 'Missing AUTH_STATUS',
        'ar' => 'حالة التفويض AUTH_STATUS مفقودة.',
    ],
    'IPAY0300034' => [
        'en' => 'Missing User Defined Field 1.',
        'ar' => 'حقل معرف المستخدم 1 مفقود.',
    ],
    'IPAY0300035' => [
        'en' => 'Missing User Defined Field 2.',
        'ar' => 'حقل معرف المستخدم 2 مفقود.',
    ],
    'IPAY0300036' => [
        'en' => 'Missing User Defined Field 3.',
        'ar' => 'حقل معرف المستخدم 3 مفقود.',
    ],
    'IPAY0300037' => [
        'en' => 'Missing User Defined Field 4.',
        'ar' => 'حقل معرف المستخدم 4 مفقود.',
    ],
    'IPAY0300038' => [
        'en' => 'Missing User Defined Field 5.',
        'ar' => 'حقل معرف المستخدم 5 مفقود.',
    ],
    'IPAY0300039' => [
        'en' => 'Missing xid',
        'ar' => 'xid مفقود.',
    ],
    'IPAY0300040' => [
        'en' => 'Missing cavv.',
        'ar' => 'cavv مفقود.',
    ],
    'IPAY0300041' => [
        'en' => 'Missing eci',
        'ar' => 'eci مفقود.',
    ],
    'IPAY0300042' => [
        'en' => 'Missing pan in PARES message format.',
        'ar' => 'pan مفقود في تنسيق رسالة PARES.',
    ],
    'IPAY0300043' => [
        'en' => 'Pan mismatch in PARES message format.',
        'ar' => 'عدم تطابق Pan في تنسيق رسالة PARES.',
    ],
    'IPAY0300044' => [
        'en' => 'Problem occurred while doing process transaction.',
        'ar' => 'حدثت مشكلة أثناء إجراء عملية المعاملة.',
    ],
    'IPAY0300046' => [
        'en' => 'Missing Action Code.',
        'ar' => 'رمز الإجراء مفقود.',
    ],
    'IPAY0300047' => [
        'en' => 'Problem occurred while getting negative bin details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل bin السلبية.',
    ],
    'IPAY0300048' => [
        'en' => 'Problem occurred while getting negative card details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل البطاقة السلبية.',
    ],
    'IPAY0300049' => [
        'en' => 'Problem occurred while updating negative card details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل البطاقة السلبية.',
    ],
    'IPAY0300050' => [
        'en' => 'Problem occurred while getting declined card details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل البطاقة المرفوضة.',
    ],
    'IPAY0300051' => [
        'en' => 'Problem occurred while getting saf details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل saf.',
    ],
    'IPAY0300052' => [
        'en' => 'Problem occurred while updating connection status in external connection.',
        'ar' => 'حدثت مشكلة أثناء تحديث حالة الاتصال في الاتصال الخارجي.',
    ],
    'IPAY0300053' => [
        'en' => 'Currency not enabled.',
        'ar' => 'العملة غير مفعلة.',
    ],
    'IPAY0300054' => [
        'en' => 'Problem occurred while adding declined card details.',
        'ar' => 'حدثت مشكلة أثناء إضافة تفاصيل البطاقة المرفوضة.',
    ],
    'IPAY0300055' => [
        'en' => 'Problem occurred while updating declined card details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل البطاقة المرفوضة.',
    ],
    'IPAY0300056' => [
        'en' => 'Problem occurred while getting card risk details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل مخاطر البطاقة.',
    ],
    'IPAY0300057' => [
        'en' => 'Problem occurred while getting transaction risk details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل مخاطر المعاملة.',
    ],
    'IPAY0300058' => [
        'en' => 'Problem occurred while getting m24 station status from connection status.',
        'ar' => 'حدثت مشكلة أثناء الحصول على حالة محطة m24 من حالة الاتصال.',
    ],
    'IPAY0300059' => [
        'en' => 'Problem occurred while doing perform action code reference id.',
        'ar' => 'حدثت مشكلة أثناء تنفيذ معرف مرجع رمز الإجراء.',
    ],
    'IPAY0300060' => [
        'en' => 'Problem occurred while getting transaction ip details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل IP للمعاملة.',
    ],
    'IPAY0300061' => [
        'en' => 'Card Number Should be Numeric.',
        'ar' => 'يجب أن يكون رقم البطاقة رقميًا.',
    ],
    'IPAY0300062' => [
        'en' => 'Missing Tranportal Password.',
        'ar' => 'كلمة مرور بوابة النقل مفقودة.',
    ],
    'IPAY0300063' => [
        'en' => 'Missing Input Data.',
        'ar' => 'بيانات الإدخال مفقودة.',
    ],
    'IPAY0300064' => [
        'en' => 'ECI is empty or null or length is not equal to 2.',
        'ar' => 'ECI فارغ أو null أو طولة لا يساوي 2.',
    ],
    'IPAY0300066' => [
        'en' => 'Problem occurred while updating PreAuth table.',
        'ar' => 'حدثت مشكلة أثناء تحديث جدول PreAuth.',
    ],
    'IPAY0300067' => [
        'en' => 'Unable to update PreAuthLOG table, payment id is null.',
        'ar' => 'تعذر تحديث جدول PreAuthLOG، معرف الدفع null.',
    ],
    'IPAY0300068' => [
        'en' => 'Problem occurred while getting connection status from extr connection.',
        'ar' => 'حدثت مشكلة أثناء الحصول على حالة الاتصال من الاتصال الخارجي.',
    ],
    'IPAY0300069' => [
        'en' => 'Transaction in progress in another tab/window.',
        'ar' => 'المعاملة قيد التنفيذ في علامة تبويب/نافذة أخرى.',
    ],
    'IPAY0300071' => [
        'en' => 'Problem occurred while inserting Faster Checkout details.',
        'ar' => 'حدثت مشكلة أثناء إدراج تفاصيل الدفع السريع.',
    ],
    'IPAY0300072' => [
        'en' => 'Problem occurred while updating PreAuthLog status.',
        'ar' => 'حدثت مشكلة أثناء تحديث حالة PreAuthLog.',
    ],
    'IPAY0300073' => [
        'en' => 'Problem occurred while inserting record in PreAuthLog.',
        'ar' => 'حدثت مشكلة أثناء إدراج سجل في PreAuthLog.',
    ],
    'IPAY0300074' => [
        'en' => 'Problem occurred while validating IMPS transaction.',
        'ar' => 'حدثت مشكلة أثناء التحقق من معاملة IMPS.',
    ],
    'IPAY0300075' => [
        'en' => 'Problem occurred while updating risk factors in Negative card and Declined card',
        'ar' => 'حدثت مشكلة أثناء تحديث عوامل المخاطرة في البطاقة السلبية والبطاقة المرفوضة',
    ],
    'IPAY0300076' => [
        'en' => 'Problem occurred while processing original transaction details.',
        'ar' => 'حدثت مشكلة أثناء معالجة تفاصيل المعاملة الأصلية.',
    ],
    'IPAY0300077' => [
        'en' => 'Negative bin details not found.',
        'ar' => 'تفاصيل bin السلبية غير موجودة.',
    ],
    'IPAY0300078' => [
        'en' => 'Negative card details not found.',
        'ar' => 'تفاصيل البطاقة السلبية غير موجودة.',
    ],
    'IPAY0300079' => [
        'en' => 'Not original transaction.',
        'ar' => 'ليست المعاملة الأصلية.',
    ],
    'IPAY0300080' => [
        'en' => 'Institution id mismatch.',
        'ar' => 'عدم تطابق معرف المؤسسة.',
    ],
    'IPAY0300081' => [
        'en' => 'Merchant id mismatch.',
        'ar' => 'عدم تطابق معرف التاجر.',
    ],
    'IPAY0300082' => [
        'en' => 'Terminal id mismatch.',
        'ar' => 'عدم تطابق معرف الطرفية.',
    ],
    'IPAY0300083' => [
        'en' => 'Invalid captcha.',
        'ar' => 'رمز التحقق (captcha) غير صالح.',
    ],
    'IPAY0300084' => [
        'en' => 'Missing debit card number.',
        'ar' => 'رقم بطاقة الخصم مفقود.',
    ],
    'IPAY0300085' => [
        'en' => 'Invalid card number length.',
        'ar' => 'طول رقم البطاقة غير صالح.',
    ],
    'IPAY0300086' => [
        'en' => 'Missing pin.',
        'ar' => 'الرقم السري مفقود.',
    ],
    'IPAY0300087' => [
        'en' => 'Invalid pin.',
        'ar' => 'الرقم السري غير صالح.',
    ],
    'IPAY0300088' => [
        'en' => 'Missing expiry month and year.',
        'ar' => 'شهر وسنة الانتهاء مفقودان.',
    ],
    'IPAY0300089' => [
        'en' => 'Invalid expiry month and year.',
        'ar' => 'شهر وسنة الانتهاء غير صالحين.',
    ],
    'IPAY0300090' => [
        'en' => 'Problem occurred while adding payment log details.',
        'ar' => 'حدثت مشكلة أثناء إضافة تفاصيل سجل الدفع.',
    ],
    'IPAY0300092' => [
        'en' => 'Problem occurred while validating IMPS.',
        'ar' => 'حدثت مشكلة أثناء التحقق من IMPS.',
    ],
    'IPAY0300093' => [
        'en' => 'Problem occurred while getting common instrument list.',
        'ar' => 'حدثت مشكلة أثناء الحصول على قائمة الأدوات المشتركة.',
    ],
    'IPAY0300094' => [
        'en' => 'Problem occurred while converting batch data into transaction data.',
        'ar' => 'حدثت مشكلة أثناء تحويل بيانات الدفعة إلى بيانات المعاملة.',
    ],
    'IPAY0300095' => [
        'en' => 'Problem occurred while converting maestro batch data into transaction data.',
        'ar' => 'حدثت مشكلة أثناء تحويل بيانات دفعة maestro إلى بيانات المعاملة.',
    ],
    'IPAY0300096' => [
        'en' => 'Invalid Common Payment Instrument List.',
        'ar' => 'قائمة أدوات الدفع المشتركة غير صالحة.',
    ],
    'IPAY0300097' => [
        'en' => 'Problem occurred while common payment instrument.',
        'ar' => 'حدثت مشكلة في أداة الدفع المشتركة.',
    ],
    'IPAY0300098' => [
        'en' => 'Problem occurred during VPAS transaction.',
        'ar' => 'حدثت مشكلة أثناء معاملة VPAS.',
    ],
    'IPAY0300099' => [
        'en' => 'Transaction denied due to invalid action Codes',
        'ar' => 'تم رفض المعاملة بسبب رموز إجراء غير صالحة.',
    ],
    'IPAY0300100' => [
        'en' => 'Problem occurred while encrypting card details.',
        'ar' => 'حدثت مشكلة أثناء تشفير تفاصيل البطاقة.',
    ],
    'IPAY0300101' => [
        'en' => 'Problem occurred while generating ISO for transaction data.',
        'ar' => 'حدثت مشكلة أثناء إنشاء ISO لبيانات المعاملة.',
    ],
    'IPAY0300102' => [
        'en' => 'Problem occurred while generating VISA ISO for transaction data.',
        'ar' => 'حدثت مشكلة أثناء إنشاء VISA ISO لبيانات المعاملة.',
    ],
    'IPAY0300103' => [
        'en' => 'Problem occurred while generating ISO for VPAS transaction.',
        'ar' => 'حدثت مشكلة أثناء إنشاء ISO لمعاملة VPAS.',
    ],
    'IPAY0300104' => [
        'en' => 'Problem occurred while generating MASTER ISO for transaction data.',
        'ar' => 'حدثت مشكلة أثناء إنشاء MASTER ISO لبيانات المعاملة.',
    ],
    'IPAY0300105' => [
        'en' => 'Problem occurred while generating VISA ISO for debit transaction.',
        'ar' => 'حدثت مشكلة أثناء إنشاء VISA ISO لمعاملة الخصم.',
    ],
    'IPAY0300106' => [
        'en' => 'Problem occurred while generating MASTER ISO for debit transaction.',
        'ar' => 'حدثت مشكلة أثناء إنشاء MASTER ISO لمعاملة الخصم.',
    ],
    'IPAY0300107' => [
        'en' => 'Problem occurred while generating ISO for debit transaction.',
        'ar' => 'حدثت مشكلة أثناء إنشاء ISO لمعاملة الخصم.',
    ],
    'IPAY0300108' => [
        'en' => 'Problem occurred while generating ISO for prepaid transaction.',
        'ar' => 'حدثت مشكلة أثناء إنشاء ISO لمعاملة الدفع المسبق.',
    ],
    'IPAY0300109' => [
        'en' => 'I-Frame Flag is not enabled',
        'ar' => 'علامة I-Frame غير مفعلة.',
    ],
    'IPAY0300110' => [
        'en' => 'Rupay transaction not enabled for Terminal.',
        'ar' => 'معاملة Rupay غير مفعلة للطرفية.',
    ],
    'IPAY0300111' => [
        'en' => 'Message got rejected by SM.',
        'ar' => 'تم رفض الرسالة من قبل SM.',
    ],
    'IPAY0300112' => [
        'en' => 'Unable to process the message.',
        'ar' => 'غير قادر على معالجة الرسالة.',
    ],
    'IPAY0300113' => [
        'en' => 'Invalid input.',
        'ar' => 'إدخال غير صالح.',
    ],
    'IPAY0300114' => [
        'en' => 'Duplicate message.',
        'ar' => 'رسالة مكررة.',
    ],
    'IPAY0300115' => [
        'en' => 'Request come with GET method so transaction declined.',
        'ar' => 'جاء الطلب بطريقة GET لذا تم رفض المعاملة.',
    ],
    'IPAY0300116' => [
        'en' => 'Transaction initiated with invalid source.',
        'ar' => 'تم بدء المعاملة بمصدر غير صالح.',
    ],
    'IPAY0300117' => [
        'en' => 'Invalid Unique id.',
        'ar' => 'معرف فريد غير صالح.',
    ],
    'IPAY0300118' => [
        'en' => 'IP is blocked.',
        'ar' => 'عنوان IP محظور.',
    ],
    'IPAY0300119' => [
        'en' => 'IP is not configured to process the message.',
        'ar' => 'لم يتم تكوين IP لمعالجة الرسالة.',
    ],
    'IPAY0300120' => [
        'en' => 'Remote IP Profile is not configured to process the message.',
        'ar' => 'لم يتم تكوين ملف تعريف IP البعيد لمعالجة الرسالة.',
    ],
    'IPAY0300121' => [
        'en' => 'Invalid input format.',
        'ar' => 'تنسيق الإدخال غير صالح.',
    ],
    'IPAY0300122' => [
        'en' => 'Connection Read Timeout.',
        'ar' => 'انتهاء مهلة قراءة الاتصال.',
    ],
    'IPAY0300123' => [
        'en' => 'Search result greater than maximum number of records allowed.',
        'ar' => 'نتيجة البحث أكبر من الحد الأقصى المسموح به لعدد السجلات.',
    ],
    'IPAY0300124' => [
        'en' => 'Problem occurred while verifying tranportal id.',
        'ar' => 'حدثت مشكلة أثناء التحقق من معرف بوابة النقل.',
    ],
    'IPAY0300125' => [
        'en' => 'Missing required data.',
        'ar' => 'البيانات المطلوبة مفقودة.',
    ],
    'IPAY0300126' => [
        'en' => 'Missing data type.',
        'ar' => 'نوع البيانات مفقود.',
    ],
    'IPAY0300127' => [
        'en' => 'Transaction declined due to payment log not updated.',
        'ar' => 'تم رفض المعاملة لعدم تحديث سجل الدفع.',
    ],
    'IPAY0300128' => [
        'en' => 'Duplicate transaction request.',
        'ar' => 'طلب معاملة مكرر.',
    ],
    'IPAY0300130' => [
        'en' => 'Problem occurred while checking IP range.',
        'ar' => 'حدثت مشكلة أثناء فحص نطاق IP.',
    ],
    'IPAY0300131' => [
        'en' => 'Problem occurred while checking negative bin range.',
        'ar' => 'حدثت مشكلة أثناء فحص نطاق bin السلبي.',
    ],
    'IPAY0400001' => [
        'en' => 'Problem occurred while getting merchant acknowledgement & transaction reversed.',
        'ar' => 'حدثت مشكلة أثناء الحصول على إقرار التاجر وتم عكس المعاملة.',
    ],
    'IPAY0400002' => [
        'en' => 'Problem occurred while parsing merchant request.',
        'ar' => 'حدثت مشكلة أثناء تحليل طلب التاجر.',
    ],
    'IPAY0400004' => [
        'en' => 'Missing Action.',
        'ar' => 'الإجراء مفقود.',
    ],
    'IPAY0400005' => [
        'en' => 'Missing Password.',
        'ar' => 'كلمة المرور مفقودة.',
    ],
    'IPAY0400006' => [
        'en' => 'Missing TrackID.',
        'ar' => 'TrackID مفقود.',
    ],
    'IPAY0400007' => [
        'en' => 'Missing Payment Id or Tranportal Id.',
        'ar' => 'معرف الدفع أو معرف بوابة النقل مفقود.',
    ],
    'IPAY0400008' => [
        'en' => 'Missing UDF5.',
        'ar' => 'UDF5 مفقود.',
    ],
    'IPAY0400009' => [
        'en' => 'Missing TransID.',
        'ar' => 'TransID مفقود.',
    ],
    'IPAY0400010' => [
        'en' => 'Missing Card Type.',
        'ar' => 'نوع البطاقة مفقود.',
    ],
    'IPAY0400011' => [
        'en' => 'Blank Request.',
        'ar' => 'طلب فارغ.',
    ],
    'IPAY0500002' => [
        'en' => 'paymentData not enabled.',
        'ar' => 'بيانات الدفع غير مفعلة.',
    ],
    'IPAY0500003' => [
        'en' => 'paymentMethod not enabled.',
        'ar' => 'طريقة الدفع غير مفعلة.',
    ],
    'IPAY0500004' => [
        'en' => 'Header not enabled.',
        'ar' => 'الرأس (Header) غير مفعل.',
    ],
    'IPAY0500005' => [
        'en' => 'Problem occurred while getting paymentData details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل بيانات الدفع.',
    ],
    'IPAY0500006' => [
        'en' => 'Problem occurred while getting paymentToken details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل رمز الدفع.',
    ],
    'IPAY0500007' => [
        'en' => 'Problem occurred while getting header details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل الرأس.',
    ],
    'IPAY0500008' => [
        'en' => 'Problem occurred while getting paymentMethod details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل طريقة الدفع.',
    ],
    'IPAY0500009' => [
        'en' => 'Problem occurred while getting Token details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل الرمز.',
    ],
    'IPAY0500010' => [
        'en' => 'Asymmetric keys do not match.',
        'ar' => 'المفاتيح غير المتماثلة لا تتطابق.',
    ],
    'IPAY0500011' => [
        'en' => 'Problem occurred while processing the CreditInstalment Request.',
        'ar' => 'حدثت مشكلة أثناء معالجة طلب تقسيط الائتمان.',
    ],
    'IPAY0500012' => [
        'en' => 'Problem occurred while validating applePay signature.',
        'ar' => 'حدثت مشكلة أثناء التحقق من توقيع Apple Pay.',
    ],
    'IPAY0500013' => [
        'en' => 'Problem occurred while getting applePay brand details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل العلامة التجارية لـ Apple Pay.',
    ],
    'IPAY0500014' => [
        'en' => 'Problem occurred while processing the applePay tranportal transaction.',
        'ar' => 'حدثت مشكلة أثناء معالجة معاملة بوابة نقل Apple Pay.',
    ],
    'IPAY0500015' => [
        'en' => '3d secure not enabled for the terminal.',
        'ar' => '3D Secure غير مفعل للطرفية.',
    ],
    'IPAY0500016' => [
        'en' => 'signature certificates count missed.',
        'ar' => 'عدد شهادات التوقيع مفقود.',
    ],
    'IPAY0500017' => [
        'en' => 'leaf certificate missing.',
        'ar' => 'الشهادة الفرعية (leaf) مفقودة.',
    ],
    'IPAY0500018' => [
        'en' => 'intermediate certificate missing.',
        'ar' => 'الشهادة الوسيطة مفقودة.',
    ],
    'IPAY0500019' => [
        'en' => 'Failed to verify apple pay signature.',
        'ar' => 'فشل التحقق من توقيع Apple Pay.',
    ],
    'IPAY0500020' => [
        'en' => 'Failed to extract sign time from apple pay signature.',
        'ar' => 'فشل استخراج وقت التوقيع من توقيع Apple Pay.',
    ],
    'IPAY0500021' => [
        'en' => 'Apple pay signature is too old.',
        'ar' => 'توقيع Apple Pay قديم جداً.',
    ],
    'IPAY0500022' => [
        'en' => 'Problem occurred while validating applePay signature time.',
        'ar' => 'حدثت مشكلة أثناء التحقق من وقت توقيع Apple Pay.',
    ],
    'IPAY0500023' => [
        'en' => 'Failed to verify apple pay certificate.',
        'ar' => 'فشل التحقق من شهادة Apple Pay.',
    ],
    'IPAY0500024' => [
        'en' => 'Problem occurred while validating rootCA.',
        'ar' => 'حدثت مشكلة أثناء التحقق من Root CA.',
    ],
    'IPAY0500025' => [
        'en' => 'Exception while initializing Code Signer Certs.',
        'ar' => 'استثناء أثناء تهيئة شهادات موقع الرمز.',
    ],
    'IPAY0500026' => [
        'en' => 'Problem occure while framing signedData.',
        'ar' => 'حدثت مشكلة أثناء تأطير البيانات الموقعة.',
    ],
    'IPAY0500027' => [
        'en' => 'Apple pay currency code and transaction currency code not matched.',
        'ar' => 'رمز عملة Apple Pay ورمز عملة المعاملة غير متطابقين.',
    ],
    'IPAY0500028' => [
        'en' => 'Apple pay amount and transaction amount not matched.',
        'ar' => 'مبلغ Apple Pay ومبلغ المعاملة غير متطابقين.',
    ],
    'IPAY0500029' => [
        'en' => 'Apple pay transaction ID not matched.',
        'ar' => 'معرف معاملة Apple Pay غير متطابق.',
    ],
    'IPAY0500030' => [
        'en' => 'Problem occurred while inserting record in Applepay Tranlog.',
        'ar' => 'حدثت مشكلة أثناء إدراج سجل في سجل معاملات Apple Pay.',
    ],
    'IPAY0500031' => [
        'en' => 'Applepay transaction not enabled for Terminal.',
        'ar' => 'معاملة Apple Pay غير مفعلة للطرفية.',
    ],
    'IPAY0500032' => [
        'en' => 'Applepay merchant not enabled.',
        'ar' => 'تاجر Apple Pay غير مفعل.',
    ],
    'IPAY0500033' => [
        'en' => 'Problem occurred while getting Applepay merchant details.',
        'ar' => 'حدثت مشكلة أثناء الحصول على تفاصيل تاجر Apple Pay.',
    ],
    'IPAY0500034' => [
        'en' => 'Payment Data missing.',
        'ar' => 'بيانات الدفع مفقودة.',
    ],
    'IPAY0500035' => [
        'en' => 'Payment Method missing.',
        'ar' => 'طريقة الدفع مفقودة.',
    ],
    'IPAY0500036' => [
        'en' => 'Payment Transaction Identifier missing.',
        'ar' => 'معرف معاملة الدفع مفقود.',
    ],
    'IPAY0500037' => [
        'en' => 'Problem occurred while getting merchant session.',
        'ar' => 'حدثت مشكلة أثناء الحصول على جلسة التاجر.',
    ],
    'IPAY0500038' => [
        'en' => 'MADA Applepay transaction is not supported.',
        'ar' => 'معاملة MADA Apple Pay غير مدعومة.',
    ],
    'IPAY0600001' => [
        'en' => 'Misssing SI data.',
        'ar' => 'بيانات SI مفقودة.',
    ],
    'IPAY0660001' => [
        'en' => 'Unable to frame the card request due to invalid Serial Number.',
        'ar' => 'تعذر تأطير طلب البطاقة بسبب رقم تسلسلي غير صالح.',
    ],
    'IPAY0660002' => [
        'en' => 'Unable to frame the card request due to invalid Profile Number.',
        'ar' => 'تعذر تأطير طلب البطاقة بسبب رقم ملف تعريف غير صالح.',
    ],
    'IPAY0660003' => [
        'en' => 'Unable to frame the card request due to invalid User ID.',
        'ar' => 'تعذر تأطير طلب البطاقة بسبب معرف مستخدم غير صالح.',
    ],
    'IPAY0660004' => [
        'en' => 'Unable to frame the card request due to invalid Operating system type.',
        'ar' => 'تعذر تأطير طلب البطاقة بسبب نوع نظام تشغيل غير صالح.',
    ],
    'IPAY0660005' => [
        'en' => 'Unable to frame the card request due to invalid IP Address.',
        'ar' => 'تعذر تأطير طلب البطاقة بسبب عنوان IP غير صالح.',
    ],
    'IPAY0660006' => [
        'en' => 'Unable to frame the card request due to invalid card Type.',
        'ar' => 'تعذر تأطير طلب البطاقة بسبب نوع بطاقة غير صالح.',
    ],
    'IPAY0660007' => [
        'en' => 'Problem occurred while framing the card fetch request.',
        'ar' => 'حدثت مشكلة أثناء تأطير طلب جلب البطاقة.',
    ],
    'IPAY0660008' => [
        'en' => 'Problem occurred while fetching the card details for saved card.',
        'ar' => 'حدثت مشكلة أثناء جلب تفاصيل البطاقة للبطاقة المحفوظة.',
    ],
    'IPAY0660009' => [
        'en' => 'Problem occurred while fetching the connection details for card list.',
        'ar' => 'حدثت مشكلة أثناء جلب تفاصيل الاتصال لقائمة البطاقات.',
    ],
    'IPAY0660010' => [
        'en' => 'Card List details are invalid to get the card details.',
        'ar' => 'تفاصيل قائمة البطاقات غير صالحة للحصول على تفاصيل البطاقة.',
    ],
    'IPAY0660011' => [
        'en' => 'Pan mismatch in Market Place Card List Response message.',
        'ar' => 'عدم تطابق Pan في رسالة استجابة قائمة بطاقات السوق.',
    ],
    'IPAY0700001' => [
        'en' => 'Credit Instalment flag not enabled.',
        'ar' => 'علامة تقسيط الائتمان غير مفعلة.',
    ],
    'IPAY0700002' => [
        'en' => 'Credit Instalment is not applicable for this bin.',
        'ar' => 'تقسيط الائتمان غير قابل للتطبيق على هذا bin.',
    ],
    'IPAY0700003' => [
        'en' => 'Unable to frame credit instalment request.',
        'ar' => 'تعذر تأطير طلب تقسيط الائتمان.',
    ],
    'IPAY0700004' => [
        'en' => 'Problem occurred while framing Acquire Ticket request.',
        'ar' => 'حدثت مشكلة أثناء تأطير طلب الحصول على تذكرة.',
    ],
    'IPAY0700005' => [
        'en' => 'Credit instalment response is empty.',
        'ar' => 'استجابة تقسيط الائتمان فارغة.',
    ],
    'IPAY0700006' => [
        'en' => 'Raw instalment plan data is empty.',
        'ar' => 'بيانات خطة التقسيط الخام فارغة.',
    ],
    'IPAY0700007' => [
        'en' => 'Failure Result Code Recieved from Prime.',
        'ar' => 'تم استلام رمز نتيجة الفشل من Prime.',
    ],
    'IPAY0700008' => [
        'en' => 'Problem occurred while inserting record in CreditInstalmetLog.',
        'ar' => 'حدثت مشكلة أثناء إدراج سجل في سجل تقسيط الائتمان.',
    ],
    'IPAY0800001' => [
        'en' => 'Apple Pay Payment Details is invalid.',
        'ar' => 'تفاصيل دفع Apple Pay غير صالحة.',
    ],
    'IPAY0800002' => [
        'en' => 'Apple Pay Network Indicator is invalid.',
        'ar' => 'مؤشر شبكة Apple Pay غير صالح.',
    ],
    'IPAY0800003' => [
        'en' => 'Apple Pay onlinePaymentCryptogram is invalid.',
        'ar' => 'التشفير للدفع عبر الإنترنت لـ Apple Pay غير صالح.',
    ],
    'IPAY0800004' => [
        'en' => 'Apple Pay transactionIdentifier is invalid.',
        'ar' => 'معرف معاملة Apple Pay غير صالح.',
    ],
    'IPAY0800005' => [
        'en' => 'Apple Pay type is invalid.',
        'ar' => 'نوع Apple Pay غير صالح.',
    ],
    'IPAY0800006' => [
        'en' => 'Apple Pay eci Indicator is invalid.',
        'ar' => 'مؤشر ECI لـ Apple Pay غير صالح.',
    ],
    'IPAY0800007' => [
        'en' => 'Apple Pay deviceManufacturerIdentifier is invalid.',
        'ar' => 'معرف الشركة المصنعة للجهاز لـ Apple Pay غير صالح.',
    ],
    'IPAY0800008' => [
        'en' => 'Apple Pay device pan is invalid.',
        'ar' => 'رقم حساب الجهاز (PAN) لـ Apple Pay غير صالح.',
    ],
    'IPAY0800009' => [
        'en' => 'Apple Pay device pan expiry details is invalid.',
        'ar' => 'تفاصيل انتهاء صلاحية PAN للجهاز لـ Apple Pay غير صالحة.',
    ],
    'IPAY0800010' => [
        'en' => 'Apple Pay currency code is invalid.',
        'ar' => 'رمز عملة Apple Pay غير صالح.',
    ],
    'IPAY0800011' => [
        'en' => 'Apple Pay PSP Flag not enabled for this Merchant.',
        'ar' => 'علامة PSP لـ Apple Pay غير مفعلة لهذا التاجر.',
    ],
    'IPAY0800012' => [
        'en' => 'Absher data is invalid.',
        'ar' => 'بيانات أبشر غير صالحة.',
    ],
    'IPAY0800013' => [
        'en' => 'Sector ID is invalid in Absher Payment data.',
        'ar' => 'معرف القطاع غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800014' => [
        'en' => 'Service code is invalid in Absher Payment data.',
        'ar' => 'رمز الخدمة غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800015' => [
        'en' => 'Beneficiary ID is invalid in Absher Payment data.',
        'ar' => 'معرف المستفيد غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800016' => [
        'en' => 'Beneficiary ID type is invalid in Absher Payment data.',
        'ar' => 'نوع معرف المستفيد غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800017' => [
        'en' => 'Sector ID length is invalid in Absher Payment data.',
        'ar' => 'طول معرف القطاع غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800019' => [
        'en' => 'Beneficiary Name is invalid in Absher Payment data.',
        'ar' => 'اسم المستفيد غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800020' => [
        'en' => 'Beneficiary Name length is invalid in Absher Payment data.',
        'ar' => 'طول اسم المستفيد غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800021' => [
        'en' => 'Branch Code is invalid in Absher Payment data.',
        'ar' => 'رمز الفرع غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800022' => [
        'en' => 'Branch Code length is invalid in Absher Payment data.',
        'ar' => 'طول رمز الفرع غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800023' => [
        'en' => 'Violation details is invalid in Absher Payment data.',
        'ar' => 'تفاصيل المخالفة غير صالحة في بيانات دفع أبشر.',
    ],
    'IPAY0800024' => [
        'en' => 'Violation count is invalid in Absher Payment data.',
        'ar' => 'عدد المخالفات غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800025' => [
        'en' => 'Violation count should be numeric in Absher Payment data.',
        'ar' => 'يجب أن يكون عدد المخالفات رقميًا في بيانات دفع أبشر.',
    ],
    'IPAY0800026' => [
        'en' => 'Violation count should be valid length in Absher Payment data.',
        'ar' => 'يجب أن يكون عدد المخالفات بطول صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800027' => [
        'en' => 'Violation list is invalid in Absher Payment data.',
        'ar' => 'قائمة المخالفات غير صالحة في بيانات دفع أبشر.',
    ],
    'IPAY0800028' => [
        'en' => 'Violation list size is invalid in Absher Payment data.',
        'ar' => 'حجم قائمة المخالفات غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800029' => [
        'en' => 'Violation id is invalid in Absher Payment data.',
        'ar' => 'معرف المخالفة غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800030' => [
        'en' => 'Violation amount is invalid in Absher Payment data.',
        'ar' => 'مبلغ المخالفة غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800031' => [
        'en' => 'Sentence details is invalid in Absher Payment data.',
        'ar' => 'تفاصيل الحكم غير صالحة في بيانات دفع أبشر.',
    ],
    'IPAY0800033' => [
        'en' => 'Number of sentences should be numeric in Absher Payment data.',
        'ar' => 'يجب أن يكون عدد الأحكام رقميًا في بيانات دفع أبشر.',
    ],
    'IPAY0800034' => [
        'en' => 'Sentence count should be valid length in Absher Payment data.',
        'ar' => 'يجب أن يكون عدد الأحكام بطول صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800035' => [
        'en' => 'Sentence list is invalid in Absher Payment data.',
        'ar' => 'قائمة الأحكام غير صالحة في بيانات دفع أبشر.',
    ],
    'IPAY0800036' => [
        'en' => 'Sentence list size is invalid in Absher Payment data.',
        'ar' => 'حجم قائمة الأحكام غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800037' => [
        'en' => 'Sentence number is invalid in Absher Payment data.',
        'ar' => 'رقم الحكم غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800038' => [
        'en' => 'Sentence amount is invalid in Absher Payment data.',
        'ar' => 'مبلغ الحكم غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800039' => [
        'en' => 'Sentence installment number is invalid in Absher Payment data.',
        'ar' => 'رقم قسط الحكم غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800040' => [
        'en' => 'Sentence number should be numeric in Absher Payment data.',
        'ar' => 'يجب أن يكون رقم الحكم رقميًا في بيانات دفع أبشر.',
    ],
    'IPAY0800041' => [
        'en' => 'Sentence installment number should be numeric in Absher Payment data.',
        'ar' => 'يجب أن يكون رقم قسط الحكم رقميًا في بيانات دفع أبشر.',
    ],
    'IPAY0800042' => [
        'en' => 'Sentence number should be valid length in Absher Payment data.',
        'ar' => 'يجب أن يكون رقم الحكم بطول صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800043' => [
        'en' => 'Sentence installment number should be valid length in Absher Payment data.',
        'ar' => 'يجب أن يكون رقم قسط الحكم بطول صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800044' => [
        'en' => 'Missing Sector id.',
        'ar' => 'معرف القطاع مفقود.',
    ],
    'IPAY0800045' => [
        'en' => 'Invalid Sector id.',
        'ar' => 'معرف القطاع غير صالح.',
    ],
    'IPAY0800046' => [
        'en' => 'Invalid IP Address.',
        'ar' => 'عنوان IP غير صالح.',
    ],
    'IPAY0800047' => [
        'en' => 'Violation ID length is invalid in Absher Payment data.',
        'ar' => 'طول معرف المخالفة غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800048' => [
        'en' => 'Payment Inquiry Type is invalid in Absher Payment data.',
        'ar' => 'نوع استعلام الدفع غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800049' => [
        'en' => 'Payment Inquiry Type should be alphanumeric in Absher Payment data.',
        'ar' => 'يجب أن يكون نوع استعلام الدفع أبجديًا رقميًا في بيانات دفع أبشر.',
    ],
    'IPAY0800050' => [
        'en' => 'Payment Inquiry Type length is invalid in Absher Payment data.',
        'ar' => 'طول نوع استعلام الدفع غير صالح في بيانات دفع أبشر.',
    ],
    'IPAY0800051' => [
        'en' => 'Problem occurred while validating Absher Payment data.',
        'ar' => 'حدثت مشكلة أثناء التحقق من بيانات دفع أبشر.',
    ],
    'IPAY0800052' => [
        'en' => 'Transaction declined due to action type not supported.',
        'ar' => 'تم رفض المعاملة لعدم دعم نوع الإجراء.',
    ],
    'IPAY0800053' => [
        'en' => 'Invalid Merchant Country Code.',
        'ar' => 'رمز بلد التاجر غير صالح.',
    ],
    'IPAY0800054' => [
        'en' => 'Card Not Supported!',
        'ar' => 'البطاقة غير مدعومة!',
    ],
    'IPAY0800055' => [
        'en' => 'Mod 10 failed for the card number.',
        'ar' => 'ف فشل مود 10 لرقم البطاقة.',
    ],
    'IPAY0800057' => [
        'en' => 'Problem occurred while updating Faster Checkout Customer details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل عميل الدفع السريع.',
    ],
    'IPAY0800058' => [
        'en' => 'Faster Checkout card number already registered.',
        'ar' => 'رقم بطاقة الدفع السريع مسجل بالفعل.',
    ],
    'IPAY0800059' => [
        'en' => 'Problem occurred while adding vpas cres details.',
        'ar' => 'حدثت مشكلة أثناء إضافة تفاصيل vpas cres.',
    ],
    'IPAY0800060' => [
        'en' => 'Problem occurred while getting details from saf dump using ReversalKey.',
        'ar' => 'حدثت مشكلة أثناء الحصول على التفاصيل من تفريغ saf باستخدام مفتاح العكس.',
    ],
    'IPAY0800061' => [
        'en' => 'Please try after sometime.',
        'ar' => 'الرجاء المحاولة بعد قليل.',
    ],
    'IPAY0860001' => [
        'en' => 'Problem occurred while loading default messages in MADA ISO Formatter.',
        'ar' => 'حدثت مشكلة أثناء تحميل الرسائل الافتراضية في منسق MADA ISO.',
    ],
    'IPAY0860002' => [
        'en' => 'Problem occurred while loading default mesages for Supporting in MADA ISO Formatter.',
        'ar' => 'حدثت مشكلة أثناء تحميل الرسائل الافتراضية للدعم في منسق MADA ISO.',
    ],
    'IPAY0860003' => [
        'en' => 'Problem occurred while formatting purchase request in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الشراء في منسق رسائل MADA ISO.',
    ],
    'IPAY0860004' => [
        'en' => 'Problem occurred while formatting Credit request in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الائتمان في منسق رسائل MADA ISO.',
    ],
    'IPAY0860005' => [
        'en' => 'Problem occurred while formatting Reverse purchase request in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس الشراء في منسق رسائل MADA ISO.',
    ],
    'IPAY0860006' => [
        'en' => 'Problem occurred while formatting authorization request in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب التفويض في منسق رسائل MADA ISO.',
    ],
    'IPAY0860007' => [
        'en' => 'Problem occurred while formatting Reverse authorization request in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب عكس التفويض في منسق رسائل MADA ISO.',
    ],
    'IPAY0860008' => [
        'en' => 'Problem occurred while formatting Authorization extension in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق تمديد التفويض في منسق رسائل MADA ISO.',
    ],
    'IPAY0860009' => [
        'en' => 'Problem occurred while formatting Capture request in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الالتقاط في منسق رسائل MADA ISO.',
    ],
    'IPAY0860010' => [
        'en' => 'Problem occurred while formatting Account verification request in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب التحقق من الحساب في منسق رسائل MADA ISO.',
    ],
    'IPAY0860011' => [
        'en' => 'Problem occurred while formatting Administrative Notification request in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب الإشعار الإداري في منسق رسائل MADA ISO.',
    ],
    'IPAY0860012' => [
        'en' => 'Problem occurred while formatting the secure data in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق البيانات الآمنة في منسق رسائل MADA ISO.',
    ],
    'IPAY0860013' => [
        'en' => 'Problem occurred while formatting the request for Supporting transactions in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب المعاملات الداعمة في منسق رسائل MADA ISO.',
    ],
    'IPAY0860014' => [
        'en' => 'Problem occurred while formatting the request Authorization supporting transactions in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق طلب تفويض المعاملات الداعمة في منسق رسائل MADA ISO.',
    ],
    'IPAY0860015' => [
        'en' => 'Problem occurred while formatting the Original transaction data in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق بيانات المعاملة الأصلية في منسق رسائل MADA ISO.',
    ],
    'IPAY0860016' => [
        'en' => 'Problem occurred while formatting the Original transaction data for Merchant Initiated transactions in MADA ISO Message Formatter.',
        'ar' => 'حدثت مشكلة أثناء تنسيق بيانات المعاملة الأصلية للمعاملات التي بدأها التاجر في منسق رسائل MADA ISO.',
    ],
    'IPAY0860017' => [
        'en' => 'Problem occurred while validating the Recurring Transaction data in MADA ISO Formatter.',
        'ar' => 'حدثت مشكلة أثناء التحقق من بيانات المعاملة المتكررة في منسق MADA ISO.',
    ],
    'IPAY0860018' => [
        'en' => 'Problem in getting the key exchange values for MAC generation.',
        'ar' => 'مشكلة في الحصول على قيم تبادل المفاتيح لتوليد MAC.',
    ],
    'IPAY0860019' => [
        'en' => 'Problem occurred while generating the MAC values.',
        'ar' => 'حدثت مشكلة أثناء توليد قيم MAC.',
    ],
    'IPAY0860020' => [
        'en' => 'MAC key is empty not able to generate MAC values.',
        'ar' => 'مفتاح MAC فارغ، غير قادر على توليد قيم MAC.',
    ],
    'IPAY0880001' => [
        'en' => 'URPay Transaction is not enabled.',
        'ar' => 'معاملة URPay غير مفعلة.',
    ],
    'IPAY0880002' => [
        'en' => 'Token generation missing data.',
        'ar' => 'بيانات توليد الرمز مفقودة.',
    ],
    'IPAY0880003' => [
        'en' => 'Token generation response is empty.',
        'ar' => 'استجابة توليد الرمز فارغة.',
    ],
    'IPAY0880004' => [
        'en' => 'Problem occuerd while generating Token.',
        'ar' => 'حدثت مشكلة أثناء توليد الرمز.',
    ],
    'IPAY0880005' => [
        'en' => 'Problem occurred while inserting URPay API details table.',
        'ar' => 'حدثت مشكلة أثناء إدراج جدول تفاصيل URPay API.',
    ],
    'IPAY0880006' => [
        'en' => 'Problem occurred while selecting URPay otp auth table.',
        'ar' => 'حدثت مشكلة أثناء اختيار جدول مصادقة URPay otp.',
    ],
    'IPAY0880007' => [
        'en' => 'Problem occuerd while performing URPay Transaction.',
        'ar' => 'حدثت مشكلة أثناء إجراء معاملة URPay.',
    ],
    'IPAY0880008' => [
        'en' => 'OTP token data is not available.',
        'ar' => 'بيانات رمز OTP غير متوفرة.',
    ],
    'IPAY0880009' => [
        'en' => 'Token is empty.',
        'ar' => 'الرمز فارغ.',
    ],
    'IPAY0880010' => [
        'en' => 'OTP validation response is empty.',
        'ar' => 'استجابة التحقق من OTP فارغة.',
    ],
    'IPAY0880011' => [
        'en' => 'Problem occurred while validating URPay otp.',
        'ar' => 'حدثت مشكلة أثناء التحقق من URPay otp.',
    ],
    'IPAY0880012' => [
        'en' => 'Problem occurred while updating URPay API details table.',
        'ar' => 'حدثت مشكلة أثناء تحديث جدول تفاصيل URPay API.',
    ],
    'IPAY0880013' => [
        'en' => 'Problem occurred while updating URPay details.',
        'ar' => 'حدثت مشكلة أثناء تحديث تفاصيل URPay.',
    ],
    'IPAY0880016' => [
        'en' => 'OTP Reverse redemption response is empty.',
        'ar' => 'استجابة عكس استرداد OTP فارغة.',
    ],
    'IPAY0880017' => [
        'en' => 'Problem occurred while inserting URPay log details.',
        'ar' => 'حدثت مشكلة أثناء إدراج تفاصيل سجل URPay.',
    ],
    'IPAY0880018' => [
        'en' => 'Problem occurred while deleting vpas pares details.',
        'ar' => 'حدثت مشكلة أثناء حذف تفاصيل vpas pares.',
    ],
    'IPAY0880019' => [
        'en' => 'Problem occuerd while generating Otp.',
        'ar' => 'حدثت مشكلة أثناء توليد Otp.',
    ],
    'IPAY0880020' => [
        'en' => 'Problem occuerd while proccessing resend Otp.',
        'ar' => 'حدثت مشكلة أثناء معالجة إعادة إرسال Otp.',
    ],
    'IPAY0880021' => [
        'en' => 'Problem occuerd while validating Otp.',
        'ar' => 'حدثت مشكلة أثناء التحقق من Otp.',
    ],
];
