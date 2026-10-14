import axios from "axios";

export const apiClient = axios.create({
  baseURL: "/api",
  headers: {
    Accept: "application/json",
  },
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: "XSRF-TOKEN",
  xsrfHeaderName: "X-XSRF-TOKEN",
});
