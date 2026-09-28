import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { qk } from '@/lib/query-keys';
import { clinicService } from '@/services/clinicService';
import { doctorService } from '@/services/doctorService';
import { patientService } from '@/services/patientService';
import { technicianService } from '@/services/technicianService';
import type { ClinicPayload, DirectoryListParams, DoctorPayload, PatientPayload, TechnicianPayload } from '@48hrs/shared/types';

/* Patients */
export const usePatients = (p: DirectoryListParams, enabled = true) =>
  useQuery({ queryKey: qk.patients.list(p), queryFn: () => patientService.list(p), placeholderData: keepPreviousData, enabled });
export const usePatient = (id?: string) => useQuery({ queryKey: qk.patients.detail(id ?? ''), queryFn: () => patientService.get(id!), enabled: !!id });

export function useSavePatient() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id?: string; payload: PatientPayload }) => (id ? patientService.update(id, payload) : patientService.create(payload)),
    onSuccess: () => void qc.invalidateQueries({ queryKey: qk.patients.all }),
  });
}
export function useDeletePatient() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (id: string) => patientService.remove(id), onSuccess: () => void qc.invalidateQueries({ queryKey: qk.patients.all }) });
}

/* Doctors */
export const useDoctors = (p: DirectoryListParams, enabled = true) =>
  useQuery({ queryKey: qk.doctors.list(p), queryFn: () => doctorService.list(p), placeholderData: keepPreviousData, enabled });
export const useDoctor = (id?: string) => useQuery({ queryKey: qk.doctors.detail(id ?? ''), queryFn: () => doctorService.get(id!), enabled: !!id });

export function useSaveDoctor() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id?: string; payload: DoctorPayload }) => (id ? doctorService.update(id, payload) : doctorService.create(payload)),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: qk.doctors.all });
      void qc.invalidateQueries({ queryKey: qk.clinics.all });
    },
  });
}
export function useDeleteDoctor() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (id: string) => doctorService.remove(id), onSuccess: () => void qc.invalidateQueries({ queryKey: qk.doctors.all }) });
}

/* Clinics */
export const useClinics = (p: DirectoryListParams, enabled = true) =>
  useQuery({ queryKey: qk.clinics.list(p), queryFn: () => clinicService.list(p), placeholderData: keepPreviousData, enabled });
export const useClinic = (id?: string) => useQuery({ queryKey: qk.clinics.detail(id ?? ''), queryFn: () => clinicService.get(id!), enabled: !!id });

export function useSaveClinic() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id?: string; payload: ClinicPayload }) => (id ? clinicService.update(id, payload) : clinicService.create(payload)),
    onSuccess: () => void qc.invalidateQueries({ queryKey: qk.clinics.all }),
  });
}
export function useDeleteClinic() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (id: string) => clinicService.remove(id), onSuccess: () => void qc.invalidateQueries({ queryKey: qk.clinics.all }) });
}

/* Technicians */
export const useTechnicians = (p: DirectoryListParams & { active?: boolean }, enabled = true) =>
  useQuery({ queryKey: qk.technicians.list(p), queryFn: () => technicianService.list(p), placeholderData: keepPreviousData, enabled });
export const useTechnician = (id?: string) =>
  useQuery({ queryKey: qk.technicians.detail(id ?? ''), queryFn: () => technicianService.get(id!), enabled: !!id });

export function useSaveTechnician() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id?: string; payload: TechnicianPayload }) => (id ? technicianService.update(id, payload) : technicianService.create(payload)),
    onSuccess: () => void qc.invalidateQueries({ queryKey: qk.technicians.all }),
  });
}

export function useDeleteTechnician() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: (id: string) => technicianService.remove(id), onSuccess: () => void qc.invalidateQueries({ queryKey: qk.technicians.all }) });
}
