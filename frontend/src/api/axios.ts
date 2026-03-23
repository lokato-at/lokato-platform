import axios from "axios";
import { apiBaseUrl } from "@/utils/api";

const api = axios.create({
  baseURL: apiBaseUrl,
  timeout: 10000,
  headers: {
    Accept: "application/json",
  },
});

export default api;
