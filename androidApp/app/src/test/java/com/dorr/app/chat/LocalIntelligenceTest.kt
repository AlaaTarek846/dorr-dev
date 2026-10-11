package com.dorr.app.chat

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** Local private intelligence (spec 156): what kind of message a notification is, judged on the phone. */
class LocalIntelligenceTest {
    @Test
    fun codes_come_before_everything_else() {
        assertEquals(LocalIntelligence.Kind.Code, LocalIntelligence.kindOf("Your verification code is 482913"))
        assertEquals(LocalIntelligence.Kind.Code, LocalIntelligence.kindOf("رمز التحقق الخاص بك 7731"))
        // Digits alone are not a code.
        assertEquals(null, LocalIntelligence.kindOf("Room 4512 is free"))
    }

    @Test
    fun money_appointments_and_questions() {
        assertEquals(LocalIntelligence.Kind.Money, LocalIntelligence.kindOf("حولتلك 250 ريال"))
        assertEquals(LocalIntelligence.Kind.Money, LocalIntelligence.kindOf("It costs 40 SAR"))
        assertEquals(LocalIntelligence.Kind.Appointment, LocalIntelligence.kindOf("نتقابل بكره الساعة 5"))
        assertEquals(LocalIntelligence.Kind.Appointment, LocalIntelligence.kindOf("Meeting at 14:30"))
        assertEquals(LocalIntelligence.Kind.Question, LocalIntelligence.kindOf("هتيجي النهارده؟"))
        assertEquals(LocalIntelligence.Kind.Question, LocalIntelligence.kindOf("Can you send it"))
        assertNull(LocalIntelligence.kindOf("Thanks a lot"))
    }
}
