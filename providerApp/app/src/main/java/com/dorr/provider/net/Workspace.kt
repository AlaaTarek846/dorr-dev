package com.dorr.provider.net

import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * The establishments I hold a seat in and the invitations waiting for me (`GET workspace`), kept in memory for the header, the
 * account's switcher and the invitations page. Read when the app opens, when a notice arrives and after I answer an invitation,
 * create an establishment or leave one — never on a timer.
 */
object Workspace {
    private val _state = MutableStateFlow<WorkspaceDto?>(null)
    val state: StateFlow<WorkspaceDto?> = _state.asStateFlow()

    suspend fun reload(): ApiResult<WorkspaceDto> {
        val result = apiCall { ApiClient.workspace.workspace() }
        if (result is ApiResult.Ok) _state.value = result.data
        return result
    }

    /**
     * The establishment to work in from [workspace]: the one chosen last time when I still hold a seat in it, else the first
     * (the server lists my own establishment first). Stores it as the `X-Establishment` of every request; null when I hold no seat.
     */
    fun pick(workspace: WorkspaceDto): String? {
        val seats = workspace.memberships.filter { it.establishment?.uuid?.isNotBlank() == true }
        val chosen = seats.firstOrNull { it.establishment?.uuid == Session.establishment.value } ?: seats.firstOrNull()
        val uuid = chosen?.establishment?.uuid
        Session.chooseEstablishment(uuid)
        return uuid
    }

    fun clear() {
        _state.value = null
    }
}
