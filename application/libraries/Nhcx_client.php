<?php defined('BASEPATH') or exit('No direct script access allowed');

/**
 * PM-JAY Phase 2: NHCX (National Health Claims Exchange) client.
 *
 * Builds FHIR R4 bundles from a PM-JAY case (coverage eligibility, pre-authorisation, claim) and exchanges them.
 * - 'simulator' mode: nothing leaves the HMS; a plausible payer response is generated locally (demo / training).
 * - 'sandbox' mode: reserved for the NHA NHCX sandbox. Needs participant registration, JWE encryption and
 *   callbacks, which belong in the ABDM bridge service (same pattern as ABDM), so it is refused here for now.
 *
 * Bundles are a simplified NHCX-style shape for demonstration; the final profiles follow the NRCES NHCX
 * implementation guide during sandbox certification.
 */
class Nhcx_client
{
    const TYPES = array(
        'eligibility' => 'Coverage eligibility check',
        'preauth'     => 'Pre-authorisation request',
        'claim'       => 'Claim submission',
        'status'      => 'Claim status check',
    );

    private $CI;

    public function __construct()
    {
        $this->CI = &get_instance();
        $this->CI->config->load('pmjay', true);
    }

    public function cfg($k)
    {
        return $this->CI->config->item($k, 'pmjay');
    }

    public function mode()
    {
        return $this->cfg('pmjay_nhcx_mode') === 'sandbox' ? 'sandbox' : 'simulator';
    }

    /**
     * Run one exchange for a case. Returns array(request_bundle, response_bundle, summary) where summary has
     * status ('complete'|'queued'|'error'), outcome text, and optionally amount / next case status.
     */
    public function exchange($type, $case)
    {
        if (!isset(self::TYPES[$type])) {
            throw new Exception('Unknown NHCX action');
        }
        $correlation = Pmjay_model::uuid();
        $request = $this->bundle($type, $case, $correlation);
        if ($this->mode() !== 'simulator') {
            throw new Exception('NHCX sandbox is not connected yet. Register as an NHCX participant, then the exchange is added to the ABDM bridge. Use PMJAY_NHCX_MODE=simulator for demos.');
        }
        list($response, $summary) = $this->simulate($type, $case, $request);
        return array($correlation, $request, $response, $summary);
    }

    // ------------------------------------------------------------------ FHIR builders
    private function hospital_name()
    {
        $r = $this->CI->db->select('name')->limit(1)->get('sch_settings')->row_array();
        return $r ? $r['name'] : 'Hospital';
    }

    private function ref($type, $id)
    {
        return array('reference' => $type . '/' . $id);
    }

    private function entry($resource)
    {
        return array('fullUrl' => $resource['resourceType'] . '/' . $resource['id'], 'resource' => $resource);
    }

    public function bundle($type, $c, $correlation)
    {
        $now = date('c');
        $gender = strtolower(substr((string) $c['gender'], 0, 1));
        $patient = array(
            'resourceType' => 'Patient', 'id' => 'P' . $c['patient_id'],
            'identifier' => array_values(array_filter(array(
                array('system' => 'https://hospital.local/mrn', 'value' => 'SH' . $c['patient_id']),
                $c['abha_number'] ? array('system' => 'https://healthid.ndhm.gov.in', 'value' => $c['abha_number']) : null,
                $c['pmjay_id'] ? array('system' => 'https://pmjay.gov.in/beneficiary-id', 'value' => $c['pmjay_id']) : null,
            ))),
            'name' => array(array('text' => $c['patient_name'])),
            'gender' => $gender === 'm' ? 'male' : ($gender === 'f' ? 'female' : 'other'),
        );
        if (!empty($c['dob']) && $c['dob'] !== '0000-00-00') {
            $patient['birthDate'] = $c['dob'];
        }
        if (!empty($c['mobileno'])) {
            $patient['telecom'] = array(array('system' => 'phone', 'value' => $c['mobileno']));
        }
        $provider = array('resourceType' => 'Organization', 'id' => 'provider', 'name' => $this->hospital_name(),
            'identifier' => array(array('system' => 'https://nhcx.abdm.gov.in/participant-code', 'value' => $this->cfg('pmjay_participant_code')))
                + ($this->cfg('pmjay_hospital_id') ? array(1 => array('system' => 'https://facility.ndhm.gov.in', 'value' => $this->cfg('pmjay_hospital_id'))) : array()));
        $payer = array('resourceType' => 'Organization', 'id' => 'payer', 'name' => $this->cfg('pmjay_payer_name'),
            'identifier' => array(array('system' => 'https://nhcx.abdm.gov.in/participant-code', 'value' => $this->cfg('pmjay_payer_code'))));
        $coverage = array('resourceType' => 'Coverage', 'id' => 'coverage', 'status' => 'active',
            'identifier' => array(array('system' => 'https://pmjay.gov.in/beneficiary-id', 'value' => (string) $c['pmjay_id'])),
            'subscriberId' => (string) $c['family_id'], 'beneficiary' => $this->ref('Patient', $patient['id']),
            'payor' => array($this->ref('Organization', 'payer')),
            'class' => array(array('type' => array('text' => 'scheme'), 'value' => $c['scheme'] ?: 'AB PM-JAY')));

        $item = array('sequence' => 1,
            'productOrService' => array('coding' => array(array('system' => 'https://pmjay.gov.in/hbp-package', 'code' => $c['pkg_code'], 'display' => $c['pkg_name']))),
            'unitPrice' => array('value' => (float) $c['package_amount'], 'currency' => 'INR'));
        $claimBase = array('id' => 'C' . $c['id'], 'status' => 'active',
            'identifier' => array(array('system' => 'https://hospital.local/pmjay-case', 'value' => 'PMJ-' . str_pad($c['id'], 5, '0', STR_PAD_LEFT))),
            'type' => array('coding' => array(array('system' => 'http://terminology.hl7.org/CodeSystem/claim-type', 'code' => 'institutional'))),
            'patient' => $this->ref('Patient', $patient['id']), 'created' => $now,
            'insurer' => $this->ref('Organization', 'payer'), 'provider' => $this->ref('Organization', 'provider'),
            'priority' => array('coding' => array(array('code' => 'normal'))),
            'insurance' => array(array('sequence' => 1, 'focal' => true, 'coverage' => $this->ref('Coverage', 'coverage'))));
        if ($c['tms_case_no']) {
            $claimBase['identifier'][] = array('system' => 'https://pmjay.gov.in/tms-case-no', 'value' => $c['tms_case_no']);
        }
        if ($c['diagnosis']) {
            $claimBase['diagnosis'] = array(array('sequence' => 1, 'diagnosisCodeableConcept' => array('text' => $c['diagnosis'])));
        }

        switch ($type) {
            case 'eligibility':
                $focal = array('resourceType' => 'CoverageEligibilityRequest', 'id' => 'E' . $c['id'], 'status' => 'active', 'purpose' => array('validation', 'benefits'),
                    'patient' => $this->ref('Patient', $patient['id']), 'created' => $now, 'insurer' => $this->ref('Organization', 'payer'),
                    'provider' => $this->ref('Organization', 'provider'),
                    'insurance' => array(array('focal' => true, 'coverage' => $this->ref('Coverage', 'coverage'))),
                    'item' => array(array('productOrService' => $item['productOrService'])));
                break;
            case 'preauth':
                $focal = array('resourceType' => 'Claim', 'use' => 'preauthorization') + $claimBase
                    + array('billablePeriod' => array('start' => $c['admission_date']), 'item' => array($item),
                        'total' => array('value' => (float) ($c['preauth_amount'] ?: $c['package_amount']), 'currency' => 'INR'));
                break;
            case 'claim':
                $docs = json_decode((string) $c['docs'], true) ?: array();
                $focal = array('resourceType' => 'Claim', 'use' => 'claim') + $claimBase
                    + array('billablePeriod' => array('start' => $c['admission_date'], 'end' => $c['discharge_date']), 'item' => array($item),
                        'supportingInfo' => array_map(function ($d, $i) {
                            return array('sequence' => $i + 1, 'category' => array('text' => 'attachment'), 'valueString' => $d);
                        }, $docs, array_keys($docs)),
                        'total' => array('value' => (float) ($c['claim_amount'] ?: $c['preauth_amount'] ?: $c['package_amount']), 'currency' => 'INR'));
                break;
            default: // status
                $focal = array('resourceType' => 'Task', 'id' => 'T' . $c['id'], 'status' => 'requested', 'intent' => 'order',
                    'code' => array('text' => 'claim-status'), 'focus' => $this->ref('Claim', 'C' . $c['id']), 'authoredOn' => $now);
        }

        return array(
            'resourceType' => 'Bundle', 'id' => $correlation, 'type' => 'collection', 'timestamp' => $now,
            'meta' => array('tag' => array(array('system' => 'https://nhcx.abdm.gov.in/api-call', 'code' => $type))),
            'entry' => array($this->entry($focal), $this->entry($patient), $this->entry($coverage), $this->entry($provider), $this->entry($payer)),
        );
    }

    // ------------------------------------------------------------------ simulator
    private function simulate($type, $c, $request)
    {
        $outcomeResource = function ($rtype, $outcome, $disposition, $extra = array()) use ($c, $request) {
            return array('resourceType' => 'Bundle', 'id' => Pmjay_model::uuid(), 'type' => 'collection', 'timestamp' => date('c'),
                'meta' => array('tag' => array(array('system' => 'https://nhcx.abdm.gov.in/simulator', 'code' => 'simulated'))),
                'entry' => array(array('resource' => array('resourceType' => $rtype, 'id' => 'R' . $c['id'], 'status' => 'active',
                    'outcome' => $outcome, 'disposition' => $disposition, 'created' => date('c'),
                    'request' => array('reference' => 'Bundle/' . $request['id'])) + $extra)));
        };
        $docsOk = count(json_decode((string) $c['docs'], true) ?: array());

        switch ($type) {
            case 'eligibility':
                $ok = !empty($c['verified']) && !empty($c['pmjay_id']);
                $text = $ok ? 'Beneficiary eligible. Family balance available: ₹5,00,000 (simulated).'
                    : 'Beneficiary not verified in BIS. Verify the PM-JAY card first.';
                return array($outcomeResource('CoverageEligibilityResponse', 'complete', $text, array('insurance' => array(array('inforce' => $ok)))),
                    array('status' => 'complete', 'outcome' => $text, 'eligible' => $ok));
            case 'preauth':
                $amount = (float) ($c['preauth_amount'] ?: $c['package_amount']);
                if (empty($c['verified'])) {
                    $text = 'Query: beneficiary not verified. Attach BIS verification and resubmit.';
                    return array($outcomeResource('ClaimResponse', 'queued', $text, array('use' => 'preauthorization')),
                        array('status' => 'query', 'outcome' => $text, 'next' => 'query'));
                }
                $text = 'Pre-authorisation approved for ₹' . number_format($amount, 2) . ' (simulated).';
                return array($outcomeResource('ClaimResponse', 'complete', $text, array('use' => 'preauthorization',
                        'preAuthRef' => 'SIM-PA-' . $c['id'] . '-' . date('His'), 'total' => array(array('category' => array('text' => 'benefit'), 'amount' => array('value' => $amount, 'currency' => 'INR'))))),
                    array('status' => 'complete', 'outcome' => $text, 'amount' => $amount, 'next' => 'preauth_approved', 'ref' => 'SIM-PA-' . $c['id']));
            case 'claim':
                $amount = (float) ($c['claim_amount'] ?: $c['preauth_amount'] ?: $c['package_amount']);
                if ($docsOk < count(Pmjay_model::STANDARD_DOCS)) {
                    $text = 'Query: mandatory documents missing (' . $docsOk . ' attached). Complete the checklist and resubmit.';
                    return array($outcomeResource('ClaimResponse', 'queued', $text, array('use' => 'claim')),
                        array('status' => 'query', 'outcome' => $text, 'next' => 'query'));
                }
                $text = 'Claim approved for ₹' . number_format($amount, 2) . '. Payment will be released by the SHA (simulated).';
                return array($outcomeResource('ClaimResponse', 'complete', $text, array('use' => 'claim',
                        'payment' => array('amount' => array('value' => $amount, 'currency' => 'INR')))),
                    array('status' => 'complete', 'outcome' => $text, 'amount' => $amount, 'next' => 'claim_approved'));
            default:
                $text = 'Current status at payer: ' . Pmjay_model::label($c['status']) . ' (simulated).';
                return array($outcomeResource('Task', 'complete', $text), array('status' => 'complete', 'outcome' => $text));
        }
    }
}
