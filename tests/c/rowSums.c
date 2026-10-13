#include <stdint.h>
#include <stddef.h>
#include <stdlib.h>

double *{{symbol}}(const double *m, size_t m_rows, size_t m_cols, int64_t *forja_len, int32_t *forja_err)
{
    *forja_len = 0;
    if (m_rows == 0) {
        return NULL;
    }
    double *out = malloc(m_rows * sizeof(double));
    if (out == NULL) {
        *forja_err = 1;
        return NULL;
    }
    for (size_t i = 0; i < m_rows; i++) {
        double sum = 0.0;
        for (size_t j = 0; j < m_cols; j++) {
            sum += m[i * m_cols + j];
        }
        out[i] = sum;
    }
    *forja_len = (int64_t) m_rows;
    return out;
}
