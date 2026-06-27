<template>
  <div id="app">
    <header v-if="!isTabletRoute" class="header">
      <div class="header-inner">
        <div class="brand-title">
          <img
            v-if="logoVisible"
            src="/branding/facility-logo.png"
            alt=""
            class="facility-logo"
            @error="logoVisible = false"
          />
          <div class="brand-text">
            <h1>Lokato</h1>
            <p v-if="branding.facilityName" class="facility-name">
              {{ branding.facilityName }}
            </p>
          </div>
        </div>

        <div class="user-status">
          <template v-if="auth.isAuthenticated">
            <span class="user-name" aria-label="Angemeldet als">
              {{ auth.user?.name }}
            </span>
            <button
              type="button"
              class="auth-link"
              @click="onLogout"
            >
              Abmelden
            </button>
          </template>
          <router-link
            v-else
            to="/login"
            class="auth-link"
          >
            Anmelden
          </router-link>
        </div>
      </div>
    </header>

    <picture v-if="!isTabletRoute && bannerVisible">
      <img
        src="/branding/facility-banner.webp"
        alt=""
        class="facility-banner"
        @error="bannerVisible = false"
      />
    </picture>

    <nav v-if="!isTabletRoute" class="nav">
      <router-link
        to="/dashboard"
        class="nav-item"
        :class="{ active: route.path.startsWith('/dashboard') }"
      >
        Dashboard
      </router-link>

      <router-link
        to="/admin/home"
        class="nav-item"
        :class="{ active: route.path.startsWith('/admin') }"
      >
        Admin
      </router-link>
    </nav>

    <hr v-if="!isTabletRoute" />
    <router-view />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watchEffect } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAuthStore } from "@/stores/authStore";
import { useBranding } from "@/composables/useBranding";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { config: branding } = useBranding();

const isTabletRoute = computed(() => route.path.startsWith("/tablet"));

// Optional branding assets — hidden if the file is missing (onerror).
const logoVisible = ref(true);
const bannerVisible = ref(true);

// Browser tab title follows the branded facility name if set.
watchEffect(() => {
  document.title = branding.value.facilityName
    ? `Lokato · ${branding.value.facilityName}`
    : "Lokato";
});

onMounted(() => {
  // Verifies the token is still valid; falls back to /login on revoke/expiry.
  if (auth.isAuthenticated) {
    void auth.refreshUser();
  }
});

async function onLogout() {
  await auth.logout();
  await router.replace("/login");
}
</script>

<style scoped>
h2 {
  font-size: 36px;
  font-family: Nunito, sans-serif;
}

h3 {
  font-size: 24px;
  font-family: Nunito, sans-serif;
}

.header {
  height: 55px;
  width: 100vw;
  position: relative;
  left: 50%;
  right: 50%;
  margin-left: -50vw;
  margin-right: -50vw;
  background: white;
  color: black;
  padding: 25px 0;
  box-sizing: border-box;
  margin-bottom: 0;
  border-radius: 0 0 16px 16px;
  box-shadow: 0px 4px 13px 0px rgba(0, 0, 0, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10;
}

.header-inner {
  width: 100%;
  max-width: 1200px;
  padding: 0 32px;
  box-sizing: border-box;
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
}

.brand-title {
  grid-column: 2;
  justify-self: center;
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 12px;
}

.facility-logo {
  height: 44px;
  width: auto;
  object-fit: contain;
}

.brand-text {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
}

.brand-text h1 {
  font-size: 30px;
  font-family: Nunito, sans-serif;
  margin: 0;
  letter-spacing: 1px;
  line-height: 1;
}

.facility-name {
  margin: 0;
  font-size: 0.85rem;
  font-weight: 500;
  color: #64748b;
  font-family: Nunito, sans-serif;
  letter-spacing: 0.3px;
}

.facility-banner {
  width: 100vw;
  height: 281px;
  display: block;
  margin-left: -50vw;
  position: relative;
  left: 50%;
  margin-top: -30px;
  margin-bottom: 20px;
  z-index: 1;
  object-fit: cover;
}

.user-status {
  grid-column: 3;
  justify-self: end;
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 0.95rem;
  font-family: Nunito, sans-serif;
}

.user-name {
  color: #475569;
  font-weight: 500;
}

.auth-link {
  font-family: inherit;
  font-size: 0.95rem;
  font-weight: 600;
  color: #2A7CD9;
  background: transparent;
  border: 1px solid #2A7CD9;
  padding: 6px 14px;
  border-radius: 8px;
  text-decoration: none;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}

.auth-link:hover {
  background: #2A7CD9;
  color: white;
}

.nav {
  display: flex;
  justify-content: center;
  gap: 15px;
  margin-bottom: 20px;
  margin-top: 20px;
  position: relative;
  z-index: 5;
}

.nav-item {
  height: 59px;
  width: 262px;
  padding: 10px 24px;
  border-radius: 15px;
  box-sizing: border-box;
  align-content: center;
  font-size: 26px;
  font-weight: 500;
  text-decoration: none;
  color: #333;
  border: 2px solid transparent;
  cursor: pointer;
  box-shadow: 0px 4px 4px 0px rgba(0, 0, 0, 0.25);
  transition: all 0.25s ease;
  background: #D9D9D9;
}

.nav-item.active {
  background: #2A7CD9;
  color: white;
}

.nav-item:hover {
  opacity: 0.9;
}

hr {
  margin: 24px 0 0;
  opacity: 0.4;
}

#app {
  max-width: 1200px;
  margin: auto;
  font-family: Nunito, "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
  text-align: center;
  background-color: #EDEDED;
  min-height: 100vh;
}

:global(html),
:global(body) {
  overflow-x: hidden;
}

@media (max-width: 700px) {
  .header-inner {
    padding: 0 12px;
    grid-template-columns: 1fr 1fr;
  }

  .brand-title {
    grid-column: 1;
    justify-self: start;
    gap: 8px;
  }

  .facility-logo {
    height: 36px;
  }

  .brand-text h1 {
    font-size: 26px;
  }

  .facility-name {
    font-size: 0.75rem;
  }

  .facility-banner {
    height: 220px;
    margin-top: -20px;
    margin-bottom: 0;
  }

  .user-status {
    grid-column: 2;
    gap: 8px;
  }

  .user-name {
    display: none;
  }

  .auth-link {
    padding: 5px 10px;
    font-size: 0.85rem;
  }

  .nav-item {
    width: auto;
    flex: 1;
    font-size: 18px;
    height: 48px;
  }
}
</style>
