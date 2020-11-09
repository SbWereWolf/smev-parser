alter table receive_table
    add is_processed boolean default false;

comment on column receive_table.is_processed
    is 'Флаг сообщение обработано';

create index receive_table_is_processed_created_at_index
    on receive_table (is_processed, created_at);

CREATE TABLE if not exists message_attachment
(
    id         bigserial not null
        constraint message_attachment_pk
            primary key,
    request_id text      not null,
    detail     jsonb     not null
);
create unique index message_attachment_request_id_uindex
    on message_attachment (request_id);



