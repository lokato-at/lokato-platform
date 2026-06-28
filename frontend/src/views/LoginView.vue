<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/authStore'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const submitting = ref(false)

async function onSubmit() {
  if (submitting.value) return
  submitting.value = true
  try {
    await auth.login(email.value, password.value)
    const target = (route.query.redirect as string | undefined) ?? '/admin/home'
    await router.replace(target)
  } catch {
    // auth.error is already populated; nothing else to do here
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="login">
    <form class="card" @submit.prevent="onSubmit" novalidate>
      <h2>Anmeldung</h2>
      <p class="subtitle">Zugriff auf den Admin-Bereich</p>

      <label for="email">E-Mail</label>
      <input
        id="email"
        v-model="email"
        type="email"
        required
        autocomplete="email"
        :disabled="submitting"
      />

      <label for="password">Passwort</label>
      <input
        id="password"
        v-model="password"
        type="password"
        required
        autocomplete="current-password"
        :disabled="submitting"
      />

      <p v-if="auth.error" class="error" role="alert">{{ auth.error }}</p>

      <button type="submit" :disabled="submitting || !email || !password">
        {{ submitting ? 'Anmelden...' : 'Anmelden' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.login {
  display: flex;
  justify-content: center;
  padding: 48px 16px;
}

.card {
  width: 100%;
  max-width: 380px;
  background: white;
  border-radius: 16px;
  padding: 28px 32px;
  box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12);
  display: grid;
  gap: 12px;
}

h2 {
  margin: 0;
  font-size: 1.6rem;
}

.subtitle {
  margin: 0 0 12px;
  color: #64748b;
  font-size: 0.95rem;
}

label {
  font-weight: 600;
  font-size: 0.9rem;
  color: #334155;
}

input {
  padding: 10px 12px;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  font-size: 1rem;
  font-family: inherit;
}

input:focus {
  outline: 2px solid #2563eb;
  outline-offset: 1px;
  border-color: transparent;
}

button {
  margin-top: 8px;
  padding: 10px 16px;
  background: #2563eb;
  color: white;
  font-weight: 600;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  font-size: 1rem;
  transition: background 0.15s;
}

button:hover:not(:disabled) { background: #1d4ed8; }
button:disabled { background: #94a3b8; cursor: not-allowed; }

.error {
  margin: 0;
  color: #b91c1c;
  background: #fef2f2;
  border: 1px solid #fecaca;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 0.9rem;
}
</style>
