<template>
    <div class="row authentication mx-0">
        <div class="col-xxl-7 col-xl-7 col-lg-12">
            <div class="row justify-content-center align-items-center h-100">
                <div class="col-xxl-6 col-xl-7 col-lg-7 col-md-7 col-sm-8 col-12">
                    <div class="p-5">
                        <router-view />
                    </div>
                </div>
            </div>
        </div>

        <AuthCoverAside />
    </div>
</template>

<script setup>
import AuthCoverAside from '../components/auth/AuthCoverAside.vue';
</script>
