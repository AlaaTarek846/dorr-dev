package com.dorr.app.network

import okhttp3.MediaType.Companion.toMediaType
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import retrofit2.HttpException
import retrofit2.Response

/** Error answers in every shape the API sends must be read, never crash the app. */
class ApiFailureTest {
    private fun failure(status: Int, json: String) =
        HttpException(Response.error<Any>(status, json.toResponseBody("application/json".toMediaType()))).apiFailure()

    @Test
    fun dataAsAnEmptyArrayDoesNotCrash() {
        // "Delete for everyone" after the time limit: `data` is [] — this used to crash.
        val f = failure(422, """{"success":false,"message":"Too late to delete for everyone.","error_code":"chat_delete_window_passed","data":[]}""")
        assertEquals("Too late to delete for everyone.", f.message)
        assertEquals("chat_delete_window_passed", f.errorCode)
        assertEquals(422, f.httpStatus)
        assertNull(f.lockedUntil)
    }

    @Test
    fun validationErrorsGiveTheFirstMessage() {
        val f = failure(422, """{"message":"Invalid","errors":{"body":["The body is required."]},"data":[]}""")
        assertEquals("The body is required.", f.message)
    }

    @Test
    fun theWalletLockStillReadsItsTime() {
        val f = failure(423, """{"message":"Locked","error_code":"wallet_pin_locked","data":{"locked_until":"2026-10-03T12:00:00Z"}}""")
        assertEquals("2026-10-03T12:00:00Z", f.lockedUntil)
    }

    @Test
    fun oddOrBrokenBodiesAreHarmless() {
        assertNull(failure(500, "<html>Server Error</html>").message)
        assertNull(failure(500, """["not","an","object"]""").message)
        assertEquals("x", failure(400, """{"message":"x","errors":["flat"],"data":null}""").message)
    }
}
